import { defineStore } from "pinia";
import { DEFAULT_MENU, type AdminMenuPrefs, type MenuGroupDef, type MenuItemDef, type MenuNode } from "@/config/admin-menu";
import { getAdminMenuPrefs, saveAdminMenuPrefs } from "@/api/cms";
import { useAuthStore } from "@/stores/auth";
import { coerceUserLevel } from "@/types";

type LegacyPrefs = {
  groupOrder: string[];
  itemOrder: Record<string, string[]>;
  hidden: string[];
  hiddenGroups?: string[];
};

function normalizePrefs(raw: AdminMenuPrefs | LegacyPrefs | null): AdminMenuPrefs | null {
  if (!raw) return null;

  const prefs = raw as Partial<AdminMenuPrefs> & LegacyPrefs;
  return {
    groupOrder: prefs.groupOrder || [],
    itemOrder: prefs.itemOrder || {},
    childOrder: prefs.childOrder || {},
    grandchildOrder: prefs.grandchildOrder || {},
    hidden: prefs.hidden || [],
    hiddenChildren: prefs.hiddenChildren || [],
    hiddenGrandchildren: prefs.hiddenGrandchildren || [],
    hiddenGroups: prefs.hiddenGroups || [],
  };
}

function orderByIds<T extends { id: string }>(items: T[], order: string[]): T[] {
  const map = new Map(items.map((item) => [item.id, item]));
  const ordered: T[] = [];

  for (const id of order) {
    const entry = map.get(id);
    if (entry) {
      ordered.push(entry);
      map.delete(id);
    }
  }

  for (const entry of map.values()) ordered.push(entry);
  return ordered;
}

function resolveChildren(children: MenuNode[] | undefined, prefs: AdminMenuPrefs, parentId: string): MenuNode[] {
  if (!children || children.length === 0) return [];

  const childOrder = prefs.childOrder[parentId] || [];
  const orderedChildren = orderByIds(children, childOrder)
    .filter((child) => !prefs.hiddenChildren.includes(child.id))
    .map((child) => {
      const orderedGrandchildren = orderByIds(child.children || [], prefs.grandchildOrder[child.id] || [])
        .filter((grandchild) => !prefs.hiddenGrandchildren.includes(grandchild.id));

      return {
        ...child,
        children: orderedGrandchildren,
      };
    });

  return orderedChildren;
}

function resolveMenu(prefsRaw: AdminMenuPrefs | null): MenuGroupDef[] {
  if (!prefsRaw) return DEFAULT_MENU;

  const prefs = normalizePrefs(prefsRaw);
  if (!prefs) return DEFAULT_MENU;

  const groupMap = new Map(DEFAULT_MENU.map((g) => [g.id, g]));
  const orderedGroups: MenuGroupDef[] = [];

  for (const groupId of prefs.groupOrder) {
    const group = groupMap.get(groupId);
    if (group) {
      orderedGroups.push(group);
      groupMap.delete(groupId);
    }
  }

  for (const group of groupMap.values()) {
    const defaultIdx = DEFAULT_MENU.findIndex((g) => g.id === group.id);
    let insertAt = 0;
    for (let i = 0; i < orderedGroups.length; i++) {
      const orderedIdx = DEFAULT_MENU.findIndex((g) => g.id === orderedGroups[i].id);
      if (orderedIdx < defaultIdx) insertAt = i + 1;
    }
    orderedGroups.splice(insertAt, 0, group);
  }

  return orderedGroups
    .filter((group) => !prefs.hiddenGroups.includes(group.id))
    .map((group) => {
      const orderedItems = orderByIds(group.items, prefs.itemOrder[group.id] || [])
        .filter((item) => !prefs.hidden.includes(item.id))
        .map((item) => ({
          ...item,
          children: resolveChildren(item.children, prefs, item.id),
        }));

      return {
        ...group,
        items: orderedItems,
      };
    })
    .filter((group) => group.items.length > 0);
}

/** Build id -> parentId map for ancestor resolution */
function buildParentMap(menu: MenuGroupDef[]): Map<string, string> {
  const map = new Map<string, string>();
  function walk(nodes: MenuNode[], parentId: string | null) {
    for (const n of nodes) {
      if (parentId) map.set(n.id, parentId);
      if (n.children?.length) walk(n.children, n.id);
    }
  }
  for (const g of menu) {
    for (const item of g.items) {
      walk([item], g.id);
    }
  }
  return map;
}

/** KERISI items that should always show when any KERISI item is in menuAccess (Ticket: L0, L1, L3, L4 — see getVisibleIds). */
const KERISI_ALWAYS_VISIBLE = ["kerisi-notifications", "kerisi-guide", "kerisi-about"];

/** Get all visible IDs: menuAccess + ancestors (for hierarchy) */
function getVisibleIds(menuAccess: string[], menu: MenuGroupDef[], userLevel?: string): Set<string> {
  const visible = new Set<string>(menuAccess);
  // Administration → "Ticket admin" uses id admin-ticket-support; also show AFSA KERISI → "Ticket" (kerisi-ticket).
  if (menuAccess.includes("admin-ticket-support")) {
    visible.add("kerisi-ticket");
  }

  const parentMap = buildParentMap(menu);
  const addAncestors = (id: string) => {
    let current: string | undefined = id;
    while (current) {
      current = parentMap.get(current);
      if (current) visible.add(current);
    }
  };
  for (const id of [...visible]) {
    addAncestors(id);
  }

  // Extra KERISI entries only when the role explicitly includes a kerisi-* menu id (not from admin-ticket-support alias).
  const hasKerisiMenuFromRole = menuAccess.some((id) => id.startsWith("kerisi-"));
  if (hasKerisiMenuFromRole) {
    for (const id of KERISI_ALWAYS_VISIBLE) visible.add(id);
    const level = coerceUserLevel(userLevel);
    if (
      level === "super_admin" ||
      level === "internal_admin" ||
      level === "agent" ||
      level === "user" ||
      level === "secondary_user"
    ) {
      visible.add("kerisi-ticket");
    }
    if (["internal_admin", "external_admin", "agent", "user", "secondary_user"].includes(level)) {
      visible.add("kerisi-desk365-log");
      visible.add("kerisi-internal-ticket-log");
    }
    for (const id of [...visible]) {
      addAncestors(id);
    }
  }

  /** Super Admin: KERISI duplicate "Ticket monitoring" is stripped — map RBAC id to Administration item. */
  const ul = coerceUserLevel(userLevel);
  if (ul === "super_admin" && menuAccess.includes("kerisi-ticket-monitoring")) {
    visible.add("ticket-monitoring");
    addAncestors("ticket-monitoring");
  }

  if (
    coerceUserLevel(userLevel) === "super_admin" &&
    (visible.has("desk365") || visible.has("internal-ticket-log") || visible.has("kerisi-knowledge"))
  ) {
    visible.add("ticket-monitoring");
    addAncestors("ticket-monitoring");
  }

  return visible;
}

/** Hide AFSA "Ticket" untuk pentadbir luaran (L2) sahaja. L0, L1, L3, L4 kekal. */
function stripKerisiAfisaTicketForStaff(menu: MenuGroupDef[], userLevel?: string): MenuGroupDef[] {
  const level = coerceUserLevel(userLevel);
  if (
    level === "super_admin" ||
    level === "internal_admin" ||
    level === "agent" ||
    level === "user" ||
    level === "secondary_user"
  )
    return menu;

  return menu
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => item.id !== "kerisi-ticket"),
    }))
    .filter((group) => group.items.length > 0);
}

/** AFSA log pendua: L0 guna Administration → Desk365 log / Ticket log. */
function stripKerisiDuplicatePlatformLogsForSuperAdmin(menu: MenuGroupDef[], userLevel?: string): MenuGroupDef[] {
  const level = coerceUserLevel(userLevel);
  if (level !== "super_admin") return menu;

  return menu
    .map((group) => ({
      ...group,
      items: group.items.filter(
        (item) => !["kerisi-desk365-log", "kerisi-internal-ticket-log", "kerisi-ticket-monitoring"].includes(item.id),
      ),
    }))
    .filter((group) => group.items.length > 0);
}

/** Menu item IDs that require specific permission(s). Value can be string or string[]. Omit = no permission required. */
const MENU_PERMISSION_MAP: Record<string, string | string[] | undefined> = {
  "kerisi-chat": "chat.use",
  "kerisi-user-chat": "chat.use",
  "admin-ticket-support": "tickets.view",
  "kerisi-ticket": undefined,
  "kerisi-desk365-log": ["knowledge.view", "knowledge.manage", "tickets.view", "tickets.create", "tickets.respond"],
  "kerisi-internal-ticket-log": ["knowledge.view", "knowledge.manage", "tickets.view", "tickets.create", "tickets.respond"],
  "internal-ticket-log": ["knowledge.view", "knowledge.manage", "tickets.view", "tickets.create", "tickets.respond"],
  "ticket-monitoring": ["knowledge.view", "knowledge.manage", "tickets.view", "tickets.create", "tickets.respond"],
  "kerisi-ticket-monitoring": ["knowledge.view", "knowledge.manage", "tickets.view", "tickets.create", "tickets.respond"],
  "kerisi-notifications": undefined,
  "kerisi-guide": undefined,
  "kerisi-about": undefined,
  "kerisi-knowledge": ["knowledge.view", "knowledge.manage"],
  "platform-messaging-notifications": "notifications.admin",
  "platform-notifications": "notifications.admin",
  "admin-database": "database.manage",
};

function hasRequiredPermission(menuId: string, permSet: Set<string>): boolean {
  const required = MENU_PERMISSION_MAP[menuId];
  if (!required) return true;
  const perms = Array.isArray(required) ? required : [required];
  return perms.some((p) => permSet.has(p));
}

/** Filter menu by RBAC menu_access. null = full access. [] = no access. */
function filterMenuByAccess(
  menu: MenuGroupDef[],
  menuAccess: string[] | null | undefined,
  userLevel?: string,
): MenuGroupDef[] {
  if (menuAccess === null || menuAccess === undefined) return menu;
  if (menuAccess.length === 0) return [];
  const visible = getVisibleIds(menuAccess, menu, userLevel);

  function hasVisibleDescendant(node: MenuNode): boolean {
    if (visible.has(node.id)) return true;
    for (const c of node.children || []) {
      if (hasVisibleDescendant(c)) return true;
    }
    return false;
  }

  function filterNode(node: MenuNode): MenuNode | null {
    if (!visible.has(node.id) && !hasVisibleDescendant(node)) return null;
    const filteredChildren = (node.children || [])
      .map(filterNode)
      .filter((n): n is MenuNode => n !== null);
    return { ...node, children: filteredChildren.length ? filteredChildren : undefined };
  }

  return menu
    .map((group) => {
      const filteredItems = group.items
        .map((item) => filterNode(item as MenuNode))
        .filter((n): n is MenuItemDef => n !== null);
      return { ...group, items: filteredItems };
    })
    .filter((group) => group.items.length > 0);
}

/** Filter menu by permissions: hide items that require a permission the user lacks. */
function filterMenuByPermissions(menu: MenuGroupDef[], permissions: string[] | undefined, userLevel?: string): MenuGroupDef[] {
  if (coerceUserLevel(userLevel) === "super_admin") return menu;
  if (!permissions) return menu;
  const permSet = new Set(permissions);

  function filterNode(node: MenuNode): MenuNode | null {
    if (!hasRequiredPermission(node.id, permSet)) return null;
    const filteredChildren = (node.children || [])
      .map(filterNode)
      .filter((n): n is MenuNode => n !== null);
    return { ...node, children: filteredChildren.length ? filteredChildren : undefined };
  }

  return menu
    .map((group) => {
      const filteredItems = group.items
        .map((item) => filterNode(item as MenuNode))
        .filter((n): n is MenuItemDef => n !== null);
      return { ...group, items: filteredItems };
    })
    .filter((group) => group.items.length > 0);
}

export const useMenuStore = defineStore("menu", {
  state: () => ({
    prefs: null as AdminMenuPrefs | null,
    initialized: false,
  }),
  getters: {
    resolvedMenu(): MenuGroupDef[] {
      const base = resolveMenu(this.prefs);
      const auth = useAuthStore();
      const menuAccess = auth.user?.menuAccess;
      const permissions = auth.user?.permissions;
      const ul = auth.user?.userLevel;
      const byAccess = filterMenuByAccess(base, menuAccess ?? null, ul);
      const byPerm = filterMenuByPermissions(byAccess, permissions, ul);
      const stripTicket = stripKerisiAfisaTicketForStaff(byPerm, ul);
      return stripKerisiDuplicatePlatformLogsForSuperAdmin(stripTicket, ul);
    },
  },
  actions: {
    async load() {
      if (this.initialized) return;
      try {
        const res = await getAdminMenuPrefs();
        this.prefs = normalizePrefs(res.data);
      } catch {
        // use defaults
      }
      this.initialized = true;
    },
    async save(prefs: AdminMenuPrefs) {
      await saveAdminMenuPrefs(prefs);
      this.prefs = normalizePrefs(prefs);
    },
  },
});
