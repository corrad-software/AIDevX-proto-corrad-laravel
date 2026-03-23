import { DEFAULT_MENU, type MenuGroupDef, type MenuNode } from "./admin-menu";

export type MenuTreeNode = {
  id: string;
  label: string;
  children?: MenuTreeNode[];
};

const GROUP_LABELS: Record<string, string> = {
  dashboard: "Dashboard",
  portal: "Webfront",
  "core-platform": "Core Platform",
  administration: "Administration",
  kerisi: "AFSA",
  development: "Development",
};

function nodeToTree(node: MenuNode): MenuTreeNode {
  return {
    id: node.id,
    label: node.label,
    children: node.children?.length ? node.children.map(nodeToTree) : undefined,
  };
}

/**
 * Build Role to Menu tree from SAME DEFAULT_MENU as sidebar.
 * Structure must match sidebar exactly so selected items = what appears in sidebar.
 */
export function buildRoleMenuTree(): MenuTreeNode[] {
  return DEFAULT_MENU.map((group: MenuGroupDef) => ({
    id: group.id,
    label: group.label || GROUP_LABELS[group.id] || group.id,
    children: group.items.map((item) => nodeToTree(item)),
  }));
}

/** Collect all menu node IDs from tree (for "select all" in group) */
export function collectAllMenuIds(tree: MenuTreeNode[]): string[] {
  const ids: string[] = [];
  function walk(nodes: MenuTreeNode[]) {
    for (const n of nodes) {
      ids.push(n.id);
      if (n.children?.length) walk(n.children);
    }
  }
  walk(tree);
  return ids;
}

/** Menu item IDs that require specific permission(s). Value can be string or string[]. Omit/undefined = no permission required. */
export const MENU_PERMISSION_MAP: Record<string, string | string[] | undefined> = {
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

export function getPermissionForMenuId(menuId: string): string | string[] | undefined {
  return MENU_PERMISSION_MAP[menuId];
}

export function getPermissionLabelsForMenuId(menuId: string): string {
  const p = MENU_PERMISSION_MAP[menuId];
  if (!p) return "";
  return Array.isArray(p) ? p.join(", ") : p;
}
