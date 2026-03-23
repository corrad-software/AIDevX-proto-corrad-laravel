export type PublishStatus = "draft" | "published" | "archived";
export type ThemeColor = "violet" | "blue" | "green" | "red" | "black-white" | "grey";
export type ThemeAppearance = "light" | "dark" | "system";

export type ApiError = { error: { code: string; message: string; details?: unknown } };

export type ApiResponse<T> = { data: T; meta?: Record<string, unknown> };

export type User = {
  id: number;
  email: string;
  name: string;
  photoUrl?: string;
  role?: string;
  userLevel?: string;
  customerCode?: string;
  emailVerifiedAt?: string | null;
  /** RBAC: menu item IDs the user can access. null/empty = full access. */
  menuAccess?: string[] | null;
  /** RBAC: permission strings the user has (for menu filtering). */
  permissions?: string[];
  /** True when super_admin is impersonating another user. */
  impersonating?: boolean;
  /** ID of the real user (super_admin) when impersonating. */
  impersonatedBy?: number;
};

/** In-app + email notification row (API camelCase). */
export type InAppNotification = {
  id: number;
  userId: number;
  notificationType: string;
  module: string | null;
  eventKey: string | null;
  title: string;
  body: string | null;
  data: Record<string, unknown> | null;
  readAt: string | null;
  emailSentAt: string | null;
  emailStatus: string;
  emailError?: string | null;
  createdAt: string;
  updatedAt: string;
  user?: { id: number; name: string; email: string };
};

export type PostInput = {
  title: string;
  slug?: string;
  excerpt?: string;
  content: string;
  status: PublishStatus;
  featuredImageId?: number | null;
  categoryIds?: number[];
};

export type Post = PostInput & {
  id: number;
  slug: string;
  publishedAt: string | null;
  createdAt: string;
  updatedAt: string;
  featuredImage?: Media | null;
  categories?: Category[];
};

export type CategoryInput = {
  name: string;
  slug?: string;
  description?: string;
};

export type Category = {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  createdAt: string;
  updatedAt: string;
  _count?: { posts: number };
};

export type PageInput = {
  title: string;
  slug?: string;
  content: string;
  status: PublishStatus;
  featuredImageId?: number | null;
};

export type Page = PageInput & {
  id: number;
  slug: string;
  publishedAt: string | null;
  createdAt: string;
  updatedAt: string;
  featuredImage?: Media | null;
};

export type Media = {
  id: number;
  filename: string;
  originalName: string;
  title: string | null;
  caption: string | null;
  description: string | null;
  mimeType: string;
  size: number;
  width: number | null;
  height: number | null;
  altText: string | null;
  path: string;
  url: string;
  createdAt: string;
};

export type MediaMetadataInput = {
  title: string;
  altText: string;
  caption: string;
  description: string;
};

export type SettingsPayload = {
  siteTitle: string;
  tagline: string;
  webfrontTitle: string;
  webfrontTagline: string;
  titleFormat: string;
  metaDescription: string;
  siteIconUrl: string;
  webfrontLogoUrl: string;
  sidebarLogoUrl: string;
  faviconUrl: string;
  language: string;
  timezone: string;
  footerText: string;
  frontPageId: number | null;
};

export type PublicSiteSettings = Pick<
  SettingsPayload,
  "siteTitle" | "tagline" | "webfrontTitle" | "webfrontTagline" | "metaDescription" | "footerText" | "siteIconUrl" | "webfrontLogoUrl" | "sidebarLogoUrl" | "faviconUrl"
> & {
  storefrontMenu: StorefrontMenuItem[];
};

export type StorefrontMenuItem = {
  id: string;
  label: string;
  href: string;
  parentId: string | null;
  openInNewTab: boolean;
};

export type Role = {
  id: number;
  name: string;
  description: string;
  permissions: string[];
  menuAccess?: string[];
  createdAt: string;
  updatedAt: string;
};

export type RoleInput = {
  name: string;
  description: string;
  permissions: string[];
  menuAccess?: string[];
};

export type Customer = {
  id: number;
  customerCode: string;
  customerName: string;
  contactNo: string | null;
  email: string | null;
  systemName: string | null;
  version: string | null;
  description: string | null;
  isActive: boolean;
  createdAt: string;
  updatedAt: string;
};

export type CustomerInput = {
  customerCode: string;
  customerName: string;
  contactNo?: string;
  email?: string;
  systemName?: string;
  version?: string;
  description?: string;
  isActive?: boolean;
};

/** AFSA hierarchy: L0–L4. See docs/user-levels-asfa-ticketing.md */
export type UserLevel =
  | "super_admin"
  | "internal_admin"
  | "external_admin"
  | "agent"
  | "user";

export const USER_LEVELS: UserLevel[] = [
  "super_admin",
  "internal_admin",
  "external_admin",
  "agent",
  "user",
];

export const USER_LEVEL_LABELS: Record<UserLevel, string> = {
  super_admin: "Level 0 — Super Admin (Developer)",
  internal_admin: "Level 1 — Pentadbir dalaman",
  external_admin: "Level 2 — Pentadbir luaran",
  agent: "Level 3 — Ejen",
  user: "Level 4 — Pengguna / pemohon",
};

/** Levels that can use SELAR / staff ticket views (0–3). */
export const STAFF_USER_LEVELS: UserLevel[] = [
  "super_admin",
  "internal_admin",
  "external_admin",
  "agent",
];

/** Normalize API value (e.g. legacy `admin` → internal_admin). */
export function coerceUserLevel(level: string | undefined): UserLevel {
  if (level === "admin") return "internal_admin";
  if (level && USER_LEVELS.includes(level as UserLevel)) return level as UserLevel;
  return "user";
}

/** Levels the actor may assign when creating/updating users (must match App\\Enums\\UserLevel). */
export function assignableUserLevelsForActor(actorLevel: string | undefined): UserLevel[] {
  const a = actorLevel ?? "user";
  switch (a) {
    case "super_admin":
      return [...USER_LEVELS];
    case "internal_admin":
      return ["external_admin", "agent", "user"];
    case "external_admin":
      return ["agent", "user"];
    case "agent":
      return ["user"];
    default:
      return [];
  }
}

export type UserDetail = {
  id: number;
  name: string;
  email: string;
  role: string;
  userLevel: UserLevel;
  customerCode?: string | null;
  isActive: boolean;
  createdAt: string;
  updatedAt: string;
  roles: { id: number; name: string }[];
  roleIds: number[];
  customers: { id: number; customerCode: string; customerName: string }[];
  customerIds: number[];
};

export type UserInput = {
  name: string;
  email: string;
  password?: string;
  roleIds?: number[];
  userLevel?: UserLevel;
  customerIds?: number[];
  isActive?: boolean;
};

export type KnowledgeDocument = {
  id: number;
  name: string;
  originalFilename: string;
  filePath: string;
  fileType: string;
  fileSize: number;
  module: string | null;
  openaiFileId: string | null;
  status: "pending" | "uploaded" | "failed";
  notes: string | null;
  uploadedBy: number | null;
  createdAt: string;
  updatedAt: string;
};

export type Desk365Ticket = {
  ticketNumber?: string;
  subject?: string;
  description?: string;
  subCategory?: string;
  type?: string;
  priority?: string;
  status?: string;
  contactName?: string;
  companyName?: string;
  createdTime?: string;
};

export type Desk365SyncLog = {
  id: number;
  userId: number | null;
  triggeredBy: string;
  totalTickets: number;
  modulesSynced: number;
  uploaded: number;
  failed: number;
  status: string;
  message: string | null;
  uploadedModules?: string[] | null;
  uploadedTicketNumbers?: string[] | null;
  uploadedModuleCounts?: { module: string; count: number }[] | Record<string, number> | null;
  uploadedTicketDetails?: {
    ticketNumber: string;
    subject: string;
    description: string;
    module: string;
    status?: string;
    type?: string;
    priority?: string;
    contactName?: string;
    companyName?: string;
    assignedAgent?: string;
    createdTime?: string;
  }[] | null;
  createdAt: string;
  user?: { id: number; name: string; email: string } | null;
};

export type ChatMessage = {
  id: number;
  chatSessionId: number;
  role: "user" | "assistant";
  content: string;
  citations: string[];
  replyToMessageId?: number | null;
  replyToUserId?: number | null;
  replyToMessage?: ChatMessage | null;
  replyToUser?: { id: number; name: string } | null;
  mentionToUserId?: number | null;
  mentionToUser?: { id: number; name: string } | null;
  createdAt: string;
};

export type ChatSessionParticipant = {
  id: number;
  name: string;
  email: string;
};

export type ChatSession = {
  id: number;
  openaiThreadId: string;
  title: string;
  moduleFilter: string | null;
  userId: number | null;
  sessionType?: "solo" | "group";
  desk365TicketId?: string | null;
  participantIds?: number[] | null;
  participants?: ChatSessionParticipant[];
  messages?: ChatMessage[];
  isFavorited?: boolean;
  createdAt: string;
  updatedAt: string;
};

/** Ticket from chat API (camelCase from CamelCaseMiddleware) */
export type Desk365TicketChat = {
  ticketNumber: string;
  subject: string;
  description: string;
  subCategory?: string;
  type?: string;
  priority?: string;
  status?: string;
  contactName?: string;
  companyName?: string;
  assignedAgent?: string;
  createdTime?: string;
};

export type SupportTicketStatus =
  | "new"
  | "assigned"
  | "in_progress"
  | "pending_requestor"
  | "resolved"
  | "closed";

export type SupportTicketPriority = "low" | "normal" | "high" | "urgent";

export type SupportTicket = {
  id: number;
  ticketNumber: string;
  subject: string;
  description: string;
  module?: string | null;
  type?: string | null;
  priority: SupportTicketPriority;
  status: SupportTicketStatus;
  createdByUserId: number;
  assignedToUserId?: number | null;
  assignedByUserId?: number | null;
  assignedAt?: string | null;
  closedByUserId?: number | null;
  closedAt?: string | null;
  createdAt: string;
  updatedAt: string;
  requestor?: { id: number; name: string; email: string; userLevel?: string | null } | null;
  assignee?: { id: number; name: string; email: string; userLevel?: string | null } | null;
};

export type SupportTicketMessage = {
  id: number;
  ticketId: number;
  userId: number;
  message: string;
  isInternal: boolean;
  createdAt: string;
  user?: { id: number; name: string; email: string; userLevel?: string | null } | null;
};

export type ChatSuggestion = {
  id: string;
  label: string;
  module: string;
};

export type ChatFavoriteItem = {
  id: number;
  message: ChatMessage;
  session: ChatSession | null;
  createdAt: string;
};

export type AuditLog = {
  id: number;
  userId: number | null;
  action: string;
  auditableType: string | null;
  auditableId: number | null;
  oldValues: Record<string, unknown> | null;
  newValues: Record<string, unknown> | null;
  ipAddress: string | null;
  userAgent: string | null;
  createdAt: string;
  user?: { id: number; name: string; email: string } | null;
};
