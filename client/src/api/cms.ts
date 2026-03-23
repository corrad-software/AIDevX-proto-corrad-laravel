import { apiRequest } from "./client";
import type {
  AuditLog,
  Category,
  CategoryInput,
  ChatFavoriteItem,
  ChatMessage,
  ChatSession,
  ChatSuggestion,
  Customer,
  CustomerInput,
  Desk365SyncLog,
  InAppNotification,
  Desk365Ticket,
  Desk365TicketChat,
  KnowledgeDocument,
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
  SupportTicket,
  SupportTicketMessage,
  StorefrontMenuItem,
  UserDetail,
  UserInput,
} from "@/types";
import type { AdminMenuPrefs } from "@/config/admin-menu";

export async function fetchDashboardSummary() {
  return apiRequest<{
    data: {
      userLevel:
        | "super_admin"
        | "internal_admin"
        | "external_admin"
        | "agent"
        | "user";
      counts: { posts: number; pages: number; media: number; users: number };
      recent: { posts: Post[]; pages: Page[] };
      support?: { ticketCount: number | null };
      unreadNotifications?: number;
    };
  }>("/api/dashboard/summary");
}

export async function listMyNotifications(params = "?limit=20&unread_only=true") {
  return apiRequest<{ data: InAppNotification[]; meta: Record<string, unknown> }>(`/api/notifications${params}`);
}

export async function getUnreadNotificationCount() {
  return apiRequest<{ data: { count: number } }>("/api/notifications/unread-count");
}

export async function markNotificationsRead(ids: number[]) {
  return apiRequest<{ data: { success: boolean } }>("/api/notifications/mark-read", {
    method: "POST",
    body: JSON.stringify({ ids }),
  });
}

export async function markAllNotificationsRead() {
  return apiRequest<{ data: { success: boolean } }>("/api/notifications/mark-all-read", {
    method: "POST",
  });
}

export async function listAdminNotifications(params = "") {
  return apiRequest<{ data: InAppNotification[]; meta: Record<string, unknown> }>(`/api/notification-admin${params}`);
}

export async function adminSendNotification(input: {
  userIds: number[];
  title: string;
  body?: string;
  notificationType?: string;
  module?: string;
  sendEmail?: boolean;
}) {
  return apiRequest<{ data: { success: boolean } }>("/api/notification-admin/send", {
    method: "POST",
    body: JSON.stringify(input),
  });
}

export async function adminResendNotificationEmail(id: number) {
  return apiRequest<{ data: InAppNotification }>(`/api/notification-admin/${id}/resend-email`, {
    method: "POST",
  });
}

export async function adminDeleteNotification(id: number) {
  return apiRequest<{ data: { success: boolean } }>(`/api/notification-admin/${id}`, {
    method: "DELETE",
  });
}

export type DashboardAnalytics = {
  ticketsByAgent: Record<string, number>;
  ticketsByModule: Record<string, number>;
  chatSessionsByUser: { user: string; count: number }[];
  topAgents: { agent: string; count: number }[];
  newTickets: Array<Record<string, unknown>>;
};

export async function fetchDashboardAnalytics() {
  return apiRequest<{ data: DashboardAnalytics }>("/api/dashboard/analytics");
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

export async function getLookups() {
  return apiRequest<{ data: { system: string[] } }>("/api/settings/lookups");
}

export async function updateLookups(payload: { system: string[] }) {
  return apiRequest<{ data: { system: string[] } }>("/api/settings/lookups", {
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

/** Search users by name or email (min 1 char in UI). Requires users list permission. */
export async function searchUsersForPicker(q: string, limit = 20) {
  const params = new URLSearchParams({ page: "1", limit: String(limit) });
  const t = q.trim();
  if (t.length > 0) params.set("q", t);
  return apiRequest<{
    data: UserDetail[];
    meta: { page: number; limit: number; total: number; totalPages: number };
  }>(`/api/users?${params.toString()}`);
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
  // PATCH avoids some proxies/WAFs that strip PUT bodies; Laravel accepts PATCH on apiResource.
  return apiRequest<{ data: Role }>(`/api/roles/${id}`, { method: "PATCH", body: JSON.stringify(input) });
}

export async function deleteRole(id: number) {
  return apiRequest<{ data: { success: boolean } }>(`/api/roles/${id}`, { method: "DELETE" });
}

// Customers
export async function listCustomers(params = "") {
  return apiRequest<{ data: Customer[]; meta: Record<string, unknown> }>(`/api/customers${params}`);
}

export async function getCustomer(id: number) {
  return apiRequest<{ data: Customer }>(`/api/customers/${id}`);
}

export async function createCustomer(input: CustomerInput) {
  return apiRequest<{ data: Customer }>("/api/customers", { method: "POST", body: JSON.stringify(input) });
}

export async function updateCustomer(id: number, input: CustomerInput) {
  return apiRequest<{ data: Customer }>(`/api/customers/${id}`, { method: "PUT", body: JSON.stringify(input) });
}

export async function deleteCustomer(id: number) {
  return apiRequest<{ data: { success: boolean } }>(`/api/customers/${id}`, { method: "DELETE" });
}

export async function listActiveCustomers() {
  return apiRequest<{ data: Customer[] }>("/api/public/customers/active");
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

// Knowledge Base
export async function listKnowledgeDocs(params = "", options?: { timeoutMs?: number }) {
  return apiRequest<{ data: KnowledgeDocument[]; meta: Record<string, unknown> }>(`/api/knowledge${params}`, { timeoutMs: options?.timeoutMs ?? 60_000 });
}

export async function uploadKnowledgeDoc(formData: FormData) {
  return apiRequest<{ data: KnowledgeDocument }>("/api/knowledge/upload", { method: "POST", body: formData });
}

export async function deleteKnowledgeDoc(id: number) {
  return apiRequest<{ data: { success: boolean } }>(`/api/knowledge/${id}`, { method: "DELETE" });
}

export async function getKnowledgeModules() {
  return apiRequest<{ data: string[] }>("/api/knowledge/modules");
}

export async function setupKerisiAI() {
  return apiRequest<{ data: { vector_store_id: string; assistant_id: string; message: string } }>("/api/knowledge/setup", { method: "POST" });
}

export async function upgradeKerisiAssistant() {
  return apiRequest<{ data: { assistant_id: string; tools: string[]; message: string } }>("/api/knowledge/upgrade-assistant", { method: "POST" });
}

export async function setupUserChatAssistant() {
  return apiRequest<{ data: { assistant_id: string; tools: string[]; message: string } }>("/api/knowledge/setup-user-chat-assistant", { method: "POST" });
}

export async function getDbStatus() {
  return apiRequest<{ data: { connected: boolean; host: string; database: string } }>("/api/knowledge/db-status");
}

export async function getDesk365Status() {
  return apiRequest<{ data: { configured: boolean; connected?: boolean; base_url?: string; message?: string } }>("/api/knowledge/desk365-status");
}

export async function getDesk365Tickets(limit = 20) {
  return apiRequest<{ data: Desk365Ticket[]; meta: { count: number } }>(`/api/knowledge/desk365-tickets?limit=${limit}`);
}

export async function syncDesk365Tickets() {
  const { ensureCsrfCookie } = await import("./client");
  await ensureCsrfCookie(true);
  return apiRequest<{
    data: {
      success: boolean;
      totalTickets: number;
      modulesSynced: number;
      uploaded: number;
      failed: number;
      message: string;
    };
  }>("/api/knowledge/sync-desk365-tickets", {
    method: "POST",
    timeoutMs: 180_000,
  });
}

export async function listDesk365SyncLogs(params = "") {
  return apiRequest<{ data: Desk365SyncLog[]; meta: Record<string, unknown> }>(`/api/knowledge/desk365-sync-logs${params}`);
}

// KERISI Chat
export async function newChatSession(opt?: {
  moduleFilter?: string;
  sessionType?: "solo" | "group";
  participantIds?: number[];
  desk365TicketId?: string;
  title?: string;
}) {
  const body: Record<string, unknown> = {};
  if (opt?.moduleFilter) body.module_filter = opt.moduleFilter;
  if (opt?.sessionType) body.session_type = opt.sessionType;
  if (opt?.participantIds?.length) body.participant_ids = opt.participantIds;
  if (opt?.desk365TicketId) body.desk365_ticket_id = opt.desk365TicketId;
  if (opt?.title) body.title = opt.title;
  return apiRequest<{ data: { session: ChatSession; messages: ChatMessage[] } }>("/api/chat/sessions", {
    method: "POST",
    body: JSON.stringify(body),
  });
}

export async function sendChatMessage(
  sessionId: number,
  message: string,
  files?: File[],
  opt?: { replyToMessageId?: number; replyToUserId?: number; mentionToUserId?: number | null },
) {
  if (files && files.length > 0) {
    const form = new FormData();
    form.append("message", message);
    if (opt?.replyToMessageId) form.append("reply_to_message_id", String(opt.replyToMessageId));
    if (opt?.replyToUserId) form.append("reply_to_user_id", String(opt.replyToUserId));
    if (opt?.mentionToUserId != null) form.append("mention_to_user_id", String(opt.mentionToUserId));
    files.forEach((f) => form.append("attachments[]", f));
    return apiRequest<{ data: ChatMessage }>(`/api/chat/sessions/${sessionId}/messages`, {
      method: "POST",
      body: form,
    });
  }
  const body: Record<string, unknown> = { message };
  if (opt?.replyToMessageId) body.reply_to_message_id = opt.replyToMessageId;
  if (opt?.replyToUserId) body.reply_to_user_id = opt.replyToUserId;
  if (opt?.mentionToUserId != null) body.mention_to_user_id = opt.mentionToUserId;
  return apiRequest<{ data: ChatMessage }>(`/api/chat/sessions/${sessionId}/messages`, {
    method: "POST",
    body: JSON.stringify(body),
  });
}

export async function getChatSession(sessionId: number) {
  return apiRequest<{ data: ChatSession }>(`/api/chat/sessions/${sessionId}`);
}

export async function updateChatSession(
  sessionId: number,
  opts: { participantIds?: number[]; desk365TicketId?: string | null }
) {
  const body: Record<string, unknown> = {};
  if (opts.participantIds !== undefined) body.participant_ids = opts.participantIds;
  if (opts.desk365TicketId !== undefined) body.desk365_ticket_id = opts.desk365TicketId;
  return apiRequest<{ data: ChatSession }>(`/api/chat/sessions/${sessionId}`, {
    method: "PUT",
    body: JSON.stringify(body),
  });
}

export async function getMyChatSessions() {
  return apiRequest<{ data: ChatSession[] }>("/api/chat/sessions");
}

// User Chat (for end users; no ticket, no SQL/schema in AI)
export async function newUserChatSession(opt?: { moduleFilter?: string; title?: string }) {
  const body: Record<string, unknown> = {};
  if (opt?.moduleFilter) body.module_filter = opt.moduleFilter;
  if (opt?.title) body.title = opt.title;
  return apiRequest<{ data: { session: ChatSession; messages: ChatMessage[] } }>("/api/chat/user/sessions", {
    method: "POST",
    body: JSON.stringify(body),
  });
}

export async function getMyUserChatSessions() {
  return apiRequest<{ data: ChatSession[] }>("/api/chat/user/sessions");
}

export async function getUserChatSession(sessionId: number) {
  return apiRequest<{ data: ChatSession }>(`/api/chat/user/sessions/${sessionId}`);
}

export async function sendUserChatMessage(sessionId: number, message: string, files?: File[]) {
  if (files && files.length > 0) {
    const form = new FormData();
    form.append("message", message);
    files.forEach((f) => form.append("attachments[]", f));
    return apiRequest<{ data: ChatMessage }>(`/api/chat/user/sessions/${sessionId}/messages`, {
      method: "POST",
      body: form,
    });
  }
  return apiRequest<{ data: ChatMessage }>(`/api/chat/user/sessions/${sessionId}/messages`, {
    method: "POST",
    body: JSON.stringify({ message }),
  });
}

export async function updateUserChatSession(sessionId: number, opts: { moduleFilter?: string }) {
  const body: Record<string, unknown> = {};
  if (opts.moduleFilter !== undefined) body.module_filter = opts.moduleFilter;
  return apiRequest<{ data: ChatSession }>(`/api/chat/user/sessions/${sessionId}`, {
    method: "PUT",
    body: JSON.stringify(body),
  });
}

export async function deleteUserChatSession(sessionId: number) {
  return apiRequest<{ data: { success: boolean } }>(`/api/chat/user/sessions/${sessionId}`, {
    method: "DELETE",
  });
}

export async function toggleUserChatSessionFavorite(sessionId: number) {
  return apiRequest<{ data: { favorited: boolean } }>(`/api/chat/user/sessions/${sessionId}/favorite`, {
    method: "POST",
  });
}

export async function searchUserChatMessages(sessionId: number, q: string) {
  return apiRequest<{ data: ChatMessage[]; meta: { count: number } }>(
    `/api/chat/user/sessions/${sessionId}/messages/search?q=${encodeURIComponent(q)}`
  );
}

export async function listUserChatFavorites(params = "") {
  return apiRequest<{ data: ChatFavoriteItem[]; meta: Record<string, unknown> }>(
    `/api/chat/user/favorites${params}`
  );
}

export async function toggleUserChatMessageFavorite(messageId: number) {
  return apiRequest<{ data: { favorited: boolean } }>(`/api/chat/user/messages/${messageId}/favorite`, {
    method: "POST",
  });
}

export async function getUserChatSuggestions() {
  return apiRequest<{ data: ChatSuggestion[] }>("/api/chat/user/suggestions");
}

export async function deleteChatSession(sessionId: number) {
  return apiRequest<{ data: { success: boolean } }>(`/api/chat/sessions/${sessionId}`, { method: "DELETE" });
}

export async function toggleChatSessionFavorite(sessionId: number) {
  return apiRequest<{ data: { favorited: boolean } }>(`/api/chat/sessions/${sessionId}/favorite`, {
    method: "POST",
  });
}

export async function listChatTickets(params = "") {
  return apiRequest<{ data: Desk365TicketChat[]; meta: Record<string, unknown> }>(`/api/chat/tickets${params}`);
}

export async function getChatTicketDetail(ticketId: string) {
  return apiRequest<{ data: { ticket: unknown; conversations: unknown[] } }>(`/api/chat/tickets/${ticketId}`);
}

export async function listSupportTickets(params = "") {
  return apiRequest<{ data: SupportTicket[]; meta: Record<string, unknown> }>(`/api/tickets${params}`);
}

export async function getSupportTicket(id: number) {
  return apiRequest<{ data: SupportTicket & { messages: SupportTicketMessage[] } }>(`/api/tickets/${id}`);
}

export async function createSupportTicket(input: {
  subject: string;
  description: string;
  module?: string;
  type?: string;
  priority?: "low" | "normal" | "high" | "urgent";
}) {
  return apiRequest<{ data: SupportTicket }>("/api/tickets", { method: "POST", body: JSON.stringify(input) });
}

export async function updateSupportTicket(
  id: number,
  input: Partial<{
    subject: string;
    description: string;
    module: string;
    type: string;
    priority: "low" | "normal" | "high" | "urgent";
    status: "new" | "assigned" | "in_progress" | "pending_requestor" | "resolved" | "closed";
  }>,
) {
  return apiRequest<{ data: SupportTicket }>(`/api/tickets/${id}`, { method: "PATCH", body: JSON.stringify(input) });
}

export async function deleteSupportTicket(id: number) {
  return apiRequest<{ data: { success: boolean } }>(`/api/tickets/${id}`, { method: "DELETE" });
}

export async function assignSupportTicket(id: number, assignedToUserId: number, note?: string) {
  return apiRequest<{ data: SupportTicket }>(`/api/tickets/${id}/assign`, {
    method: "POST",
    body: JSON.stringify({ assignedToUserId, note }),
  });
}

export async function replySupportTicket(
  id: number,
  input: { message: string; isInternal?: boolean; status?: "in_progress" | "pending_requestor" | "resolved" | "closed" },
) {
  return apiRequest<{ data: SupportTicketMessage }>(`/api/tickets/${id}/reply`, {
    method: "POST",
    body: JSON.stringify(input),
  });
}

export async function closeSupportTicket(id: number) {
  return apiRequest<{ data: SupportTicket }>(`/api/tickets/${id}/close`, { method: "POST" });
}

export async function listChatFavorites(params = "") {
  return apiRequest<{ data: ChatFavoriteItem[]; meta: Record<string, unknown> }>(`/api/chat/favorites${params}`);
}

export async function toggleChatMessageFavorite(messageId: number) {
  return apiRequest<{ data: { favorited: boolean } }>(`/api/chat/messages/${messageId}/favorite`, {
    method: "POST",
  });
}

export async function searchChatMessages(sessionId: number, q: string) {
  return apiRequest<{ data: ChatMessage[]; meta: Record<string, unknown> }>(
    `/api/chat/sessions/${sessionId}/messages/search?q=${encodeURIComponent(q)}`,
  );
}

export async function getChatSuggestions() {
  return apiRequest<{ data: ChatSuggestion[] }>("/api/chat/suggestions");
}
