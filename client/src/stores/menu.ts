import { defineStore } from "pinia";
import { DEFAULT_MENU, type AdminMenuPrefs, type MenuGroupDef, type MenuItemDef, type MenuNode } from "@/config/admin-menu";
import { getAdminMenuPrefs, saveAdminMenuPrefs } from "@/api/cms";
import { useAuthStore } from "@/stores/auth";

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

/** KERISI items that should always show when any KERISI item is in menuAccess */
const KERISI_ALWAYS_VISIBLE = ["kerisi-ticket", "kerisi-notifications", "kerisi-guide", "kerisi-about"];

/** Get all visible IDs: menuAccess + ancestors (for hierarchy) */
function getVisibleIds(menuAccess: string[], menu: MenuGroupDef[]): Set<string> {
  const visible = new Set<string>(menuAccess);
  const parentMap = buildParentMap(menu);
  for (const id of menuAccess) {
    let current: string | undefined = id;
    while (current) {
      current = parentMap.get(current);
      if (current) visible.add(current);
    }
  }
  const hasAnyKerisi = menuAccess.some((id) => id.startsWith("kerisi-"));
  if (hasAnyKerisi) {
    for (const id of KERISI_ALWAYS_VISIBLE) visible.add(id);
  }
  return visible;
}

/** Menu item IDs that require specific permission(s). Value can be string or string[]. Omit = no permission required. */
const MENU_PERMISSION_MAP: Record<string, string | string[] | undefined> = {
  "kerisi-chat": "chat.use",
  "kerisi-user-chat": "chat.use",
  "kerisi-ticket": ["tickets.view", "tickets.respond"],
  "ticket-365-log": ["tickets.view", "tickets.respond"],
  "kerisi-notifications": undefined,
  "kerisi-guide": undefined,
  "kerisi-about": undefined,
  "kerisi-knowledge": ["knowledge.view", "knowledge.manage"],
  "platform-messaging-notifications": "notifications.admin",
  "platform-notifications": "notifications.admin",
};

function hasRequiredPermission(menuId: string, permSet: Set<string>): boolean {
  const required = MENU_PERMISSION_MAP[menuId];
  if (!required) return true;
  const perms = Array.isArray(required) ? required : [required];
  return perms.some((p) => permSet.has(p));
}

/** Filter menu by RBAC menu_access. null = full access. [] = no access. */
function filterMenuByAccess(menu: MenuGroupDef[], menuAccess: string[] | null | undefined): MenuGroupDef[] {
  if (menuAccess === null || menuAccess === undefined) return menu;
  if (menuAccess.length === 0) return [];
  const visible = getVisibleIds(menuAccess, menu);

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
function filterMenuByPermissions(menu: MenuGroupDef[], permissions: string[] | undefined): MenuGroupDef[] {
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
      const byAccess = filterMenuByAccess(base, menuAccess ?? null);
      return filterMenuByPermissions(byAccess, permissions);
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
