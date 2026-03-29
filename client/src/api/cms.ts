import { apiRequest } from "./client";
import type {
  AuditLog,
  Category,
  DbEditorColumnType,
  DbEditorRowsPayload,
  DbEditorSchema,
  CategoryInput,
  Media,
  MediaMetadataInput,
  Page,
  PageInput,
  Post,
  PostInput,
  PublicSiteSettings,
  Role,
  RoleInput,
  SettingsPayload,
  StorefrontMenuItem,
  UserDetail,
  UserInput,
} from "@/types";
import type { AdminMenuPrefs } from "@/config/admin-menu";

export async function fetchDashboardSummary() {
  return apiRequest<{ data: { counts: { posts: number; pages: number; media: number }; recent: { posts: Post[]; pages: Page[] } } }>(
    "/api/dashboard/summary",
  );
}

export async function listPosts(params = "") {
  return apiRequest<{ data: Post[]; meta: Record<string, unknown> }>(`/api/posts${params}`);
}

export async function getPost(id: number) {
  return apiRequest<{ data: Post }>(`/api/posts/${id}`);
}

export async function createPost(input: PostInput) {
  return apiRequest<{ data: Post }>("/api/posts", { method: "POST", body: JSON.stringify(input) });
}

export async function updatePost(id: number, input: PostInput) {
  return apiRequest<{ data: Post }>(`/api/posts/${id}`, { method: "PUT", body: JSON.stringify(input) });
}

export async function deletePost(id: number) {
  return apiRequest<{ data: { success: boolean } }>(`/api/posts/${id}`, { method: "DELETE" });
}

// Categories
export async function listCategories(params = "") {
  return apiRequest<{ data: Category[]; meta: Record<string, unknown> }>(`/api/categories${params}`);
}

export async function getCategory(id: number) {
  return apiRequest<{ data: Category }>(`/api/categories/${id}`);
}

export async function createCategory(input: CategoryInput) {
  return apiRequest<{ data: Category }>("/api/categories", { method: "POST", body: JSON.stringify(input) });
}

export async function updateCategory(id: number, input: CategoryInput) {
  return apiRequest<{ data: Category }>(`/api/categories/${id}`, { method: "PUT", body: JSON.stringify(input) });
}

export async function deleteCategory(id: number) {
  return apiRequest<{ data: { success: boolean } }>(`/api/categories/${id}`, { method: "DELETE" });
}

export async function listPages(params = "") {
  return apiRequest<{ data: Page[]; meta: Record<string, unknown> }>(`/api/pages${params}`);
}

export async function getPage(id: number) {
  return apiRequest<{ data: Page }>(`/api/pages/${id}`);
}

export async function createPage(input: PageInput) {
  return apiRequest<{ data: Page }>("/api/pages", { method: "POST", body: JSON.stringify(input) });
}

export async function updatePage(id: number, input: PageInput) {
  return apiRequest<{ data: Page }>(`/api/pages/${id}`, { method: "PUT", body: JSON.stringify(input) });
}

export async function deletePage(id: number) {
  return apiRequest<{ data: { success: boolean } }>(`/api/pages/${id}`, { method: "DELETE" });
}

export async function listMedia() {
  return apiRequest<{ data: Media[] }>("/api/media");
}

export async function uploadMedia(file: File) {
  const formData = new FormData();
  formData.append("file", file);
  return apiRequest<{ data: Media }>("/api/media/upload", { method: "POST", body: formData });
}

export async function removeMedia(id: number) {
  return apiRequest<{ data: { success: boolean } }>(`/api/media/${id}`, { method: "DELETE" });
}

export async function updateMediaMetadata(id: number, input: MediaMetadataInput) {
  return apiRequest<{ data: Media }>(`/api/media/${id}`, { method: "PUT", body: JSON.stringify(input) });
}

export async function getSettings() {
  return apiRequest<{ data: SettingsPayload }>("/api/settings");
}

export async function updateSettings(payload: SettingsPayload) {
  return apiRequest<{ data: SettingsPayload }>("/api/settings", {
    method: "PUT",
    body: JSON.stringify(payload),
  });
}

export async function getAdminMenuPrefs() {
  return apiRequest<{ data: AdminMenuPrefs | null }>("/api/settings/admin-menu-prefs");
}

export async function saveAdminMenuPrefs(prefs: AdminMenuPrefs) {
  return apiRequest<{ data: AdminMenuPrefs }>("/api/settings/admin-menu-prefs", {
    method: "PUT",
    body: JSON.stringify(prefs),
  });
}

export async function getStorefrontMenu() {
  return apiRequest<{ data: StorefrontMenuItem[] }>("/api/settings/storefront-menu");
}

export async function saveStorefrontMenu(items: StorefrontMenuItem[]) {
  return apiRequest<{ data: StorefrontMenuItem[] }>("/api/settings/storefront-menu", {
    method: "PUT",
    body: JSON.stringify(items),
  });
}

export async function getPublicSiteSettings() {
  return apiRequest<{ data: PublicSiteSettings }>("/api/public/site");
}

export async function getPublicFrontPage() {
  return apiRequest<{ data: Page; meta?: { source?: string } }>("/api/public/pages/frontpage");
}

export async function getPublicPageBySlug(slug: string) {
  return apiRequest<{ data: Page }>(`/api/public/pages/${encodeURIComponent(slug)}`);
}

// Users
export async function listUsers() {
  return apiRequest<{ data: UserDetail[] }>("/api/users");
}

export async function getUser(id: number) {
  return apiRequest<{ data: UserDetail }>(`/api/users/${id}`);
}

export async function createUser(input: UserInput) {
  return apiRequest<{ data: UserDetail }>("/api/users", { method: "POST", body: JSON.stringify(input) });
}

export async function updateUser(id: number, input: UserInput) {
  return apiRequest<{ data: UserDetail }>(`/api/users/${id}`, { method: "PUT", body: JSON.stringify(input) });
}

export async function deleteUser(id: number) {
  return apiRequest<{ data: { success: boolean } }>(`/api/users/${id}`, { method: "DELETE" });
}

// Roles
export async function listRoles() {
  return apiRequest<{ data: Role[] }>("/api/roles");
}

export async function getRole(id: number) {
  return apiRequest<{ data: Role }>(`/api/roles/${id}`);
}

export async function createRole(input: RoleInput) {
  return apiRequest<{ data: Role }>("/api/roles", { method: "POST", body: JSON.stringify(input) });
}

export async function updateRole(id: number, input: RoleInput) {
  return apiRequest<{ data: Role }>(`/api/roles/${id}`, { method: "PUT", body: JSON.stringify(input) });
}

export async function deleteRole(id: number) {
  return apiRequest<{ data: { success: boolean } }>(`/api/roles/${id}`, { method: "DELETE" });
}

// Audit Logs
export async function listAuditLogs(params = "") {
  return apiRequest<{ data: AuditLog[]; meta: Record<string, unknown> }>(`/api/audit-logs${params}`);
}

// Developers Guide
export async function getDevelopersGuide() {
  return apiRequest<{ data: { content: string; syncFiles: { filename: string; path?: string; exists: boolean; inSync: boolean; readOnly?: boolean; role?: "canonical" | "mirror" }[] } }>("/api/developers-guide");
}

export async function updateDevelopersGuide(content: string) {
  return apiRequest<{ data: { success: boolean; syncFiles: { filename: string; path?: string; exists: boolean; inSync: boolean; readOnly?: boolean; role?: "canonical" | "mirror" }[] } }>("/api/developers-guide", {
    method: "PUT",
    body: JSON.stringify({ content }),
  });
}

// Database Editor
export async function listDbTables() {
  return apiRequest<{ data: { tables: string[]; driver: string; database: string } }>("/api/db-editor/tables");
}

export async function getDbTableSchema(table: string) {
  return apiRequest<{ data: DbEditorSchema }>(`/api/db-editor/tables/${encodeURIComponent(table)}/schema`);
}

export async function getDbTableRows(table: string, page = 1, limit = 25) {
  return apiRequest<{ data: DbEditorRowsPayload }>(
    `/api/db-editor/tables/${encodeURIComponent(table)}/rows?page=${page}&limit=${limit}`,
  );
}

export async function createDbRow(table: string, data: Record<string, unknown>) {
  return apiRequest<{ data: { message: string } }>(`/api/db-editor/tables/${encodeURIComponent(table)}/rows`, {
    method: "POST",
    body: JSON.stringify({ data }),
  });
}

export async function updateDbRow(table: string, rowId: string | number, data: Record<string, unknown>) {
  return apiRequest<{ data: { message: string } }>(
    `/api/db-editor/tables/${encodeURIComponent(table)}/rows/${encodeURIComponent(String(rowId))}`,
    {
      method: "PUT",
      body: JSON.stringify({ data }),
    },
  );
}

export async function deleteDbRow(table: string, rowId: string | number) {
  return apiRequest<{ data: { message: string } }>(
    `/api/db-editor/tables/${encodeURIComponent(table)}/rows/${encodeURIComponent(String(rowId))}`,
    { method: "DELETE" },
  );
}

export async function createDbTable(input: {
  table: string;
  columns: { name: string; type: DbEditorColumnType; nullable?: boolean }[];
  primaryKey?: string;
  withTimestamps?: boolean;
}) {
  return apiRequest<{ data: { message: string } }>("/api/db-editor/tables", {
    method: "POST",
    body: JSON.stringify(input),
  });
}

export async function deleteDbTable(table: string) {
  return apiRequest<{ data: { message: string } }>(`/api/db-editor/tables/${encodeURIComponent(table)}`, {
    method: "DELETE",
  });
}
