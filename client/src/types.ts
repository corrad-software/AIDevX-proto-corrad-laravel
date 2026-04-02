export type PublishStatus = "draft" | "published" | "archived";
export type ThemeColor = "violet" | "blue" | "green" | "red" | "black-white" | "grey";
export type ThemeAppearance = "light" | "dark" | "system";

export type ApiError = { error: { code: string; message: string; details?: unknown } };

export type ApiResponse<T> = { data: T; meta?: Record<string, unknown> };

/** Settings: code + description row (user level, user category, …). API returns camelCase keys. */
export type CodeDescLookupRow = { code: string; desc: string };

/** @deprecated Use {@link CodeDescLookupRow}; kept for existing imports. */
export type UserLevelLookupRow = CodeDescLookupRow;

/** Rows from `customer_user` — one customer may appear multiple times with different `systemName`. */
export type CustomerLink = {
  id: number;
  customerCode: string;
  customerName: string;
  systemName: string | null;
};

export type User = {
  id: number;
  email: string;
  name: string;
  photoUrl?: string;
  role?: string;
  userLevel?: string;
  customerCode?: string;
  /** From linked Customer row (for ticket form display). */
  customerDisplayName?: string | null;
  /** From linked Customer row (system / product name). */
  systemDisplayName?: string | null;
  /** All customers/systems linked to the user (tickets, profile). */
  customerLinks?: CustomerLink[];
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
  /** Present when returned by `GET /api/settings` / `getAll()` (same source as Lookups UI). */
  lookupSystem?: string[];
  lookupUserLevel?: UserLevelLookupRow[];
  lookupUserCategory?: CodeDescLookupRow[];
  lookupUserSegment?: CodeDescLookupRow[];
  lookupUserJenisPengguna?: CodeDescLookupRow[];
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

/** AFSA hierarchy: L0–L5. See docs/user-levels-asfa-ticketing.md */
export type UserLevel =
  | "super_admin"
  | "internal_admin"
  | "external_admin"
  | "agent"
  | "user"
  | "secondary_user";

export const USER_LEVELS: UserLevel[] = [
  "super_admin",
  "internal_admin",
  "external_admin",
  "agent",
  "user",
  "secondary_user",
];

export const USER_LEVEL_LABELS: Record<UserLevel, string> = {
  super_admin: "Level 0 — Super Admin (Developer)",
  internal_admin: "Level 1 — Pentadbir dalaman",
  external_admin: "Level 2 — Pentadbir luaran",
  agent: "Level 3 — Ejen",
  user: "Level 4 — Pengguna / pemohon",
  secondary_user: "Level 5 — Pengguna peringkat kedua",
};

/** Settings lookup `code` (0–5) → canonical `users.user_level` value. Matches `App\Enums\UserLevel::numericTier`. */
export const LOOKUP_CODE_TO_USER_LEVEL: Record<string, UserLevel> = {
  "0": "super_admin",
  "1": "internal_admin",
  "2": "external_admin",
  "3": "agent",
  "4": "user",
  "5": "secondary_user",
};

/**
 * Map a Settings lookup `code` to canonical UserLevel. Supports:
 * - "0".."5", "00".."05", leading zeros
 * - snake_case level names: internal_admin, super_admin, secondary_user, …
 * - short forms: l0..l5 (case-insensitive)
 * Codes outside 0–5 or unknown names are not assignable as user_level in the API.
 */
export function userLevelForLookupCode(code: string | number | undefined | null): UserLevel | null {
  const raw = String(code ?? "").trim();
  if (!raw) {
    return null;
  }
  const direct = LOOKUP_CODE_TO_USER_LEVEL[raw];
  if (direct) {
    return direct;
  }
  if (/^\d+$/.test(raw)) {
    const n = parseInt(raw, 10);
    if (n >= 0 && n <= 5) {
      return LOOKUP_CODE_TO_USER_LEVEL[String(n)] ?? null;
    }
    return null;
  }
  const slug = raw
    .toLowerCase()
    .replace(/[\s-]+/g, "_")
    .replace(/_+/g, "_")
    .replace(/^_|_$/g, "");
  if (USER_LEVELS.includes(slug as UserLevel)) {
    return slug as UserLevel;
  }
  const m = /^l([0-5])$/i.exec(slug);
  if (m) {
    return LOOKUP_CODE_TO_USER_LEVEL[m[1]] ?? null;
  }
  const lm = /^level[_]?([0-5])$/i.exec(slug);
  if (lm) {
    return LOOKUP_CODE_TO_USER_LEVEL[lm[1]] ?? null;
  }
  return null;
}

/** Fixed tier code (0–5) for each canonical level (matches Settings lookup). */
export function lookupCodeForUserLevel(level: UserLevel): string {
  const map: Record<UserLevel, string> = {
    super_admin: "0",
    internal_admin: "1",
    external_admin: "2",
    agent: "3",
    user: "4",
    secondary_user: "5",
  };
  return map[level];
}

export type UserLevelSelectOption = {
  /** Stable id (duplicate `value` is allowed for several lookup rows). */
  optionId: string;
  value: UserLevel;
  /** Tier code from Settings lookup, or default 0–4. */
  code: string;
  /** Description from Settings lookup, or fallback label text. */
  desc: string;
};

/**
 * Dropdown options for create/edit user: every Settings lookup row that maps to an allowed tier
 * is included (same tier may appear multiple times). Missing tiers get one fallback row.
 * Order: tier 0 → 4, and within a tier the order matches the lookup array in Settings.
 */
export function userLevelOptionsForSelect(
  allowedLevels: readonly UserLevel[],
  lookupRows: readonly UserLevelLookupRow[] | undefined | null,
): UserLevelSelectOption[] {
  const allowed = new Set<UserLevel>(allowedLevels);
  const rows = lookupRows ?? [];
  const options: UserLevelSelectOption[] = [];

  for (const tier of USER_LEVELS) {
    if (!allowed.has(tier)) {
      continue;
    }
    let rowIndex = 0;
    for (const row of rows) {
      const lvl = userLevelForLookupCode(row.code);
      if (lvl !== tier) {
        continue;
      }
      const code = String(row.code ?? "").trim();
      const desc = String(row.desc ?? "").trim();
      if (!code || !desc) {
        continue;
      }
      options.push({
        optionId: `ul-${tier}-${rowIndex}-${code}`,
        value: tier,
        code,
        desc,
      });
      rowIndex++;
    }
    if (rowIndex === 0) {
      options.push({
        optionId: `ul-fallback-${tier}`,
        value: tier,
        code: lookupCodeForUserLevel(tier),
        desc: USER_LEVEL_LABELS[tier],
      });
    }
  }

  return options;
}

/** Single-line label for tables / compact read-only (code — desc). Last matching lookup row wins. */
export function displayLabelForUserLevel(
  level: UserLevel,
  lookupRows: readonly UserLevelLookupRow[] | undefined | null,
): string {
  let last: UserLevelLookupRow | null = null;
  for (const row of lookupRows ?? []) {
    if (userLevelForLookupCode(row.code) === level) {
      last = row;
    }
  }
  if (last) {
    const c = String(last.code ?? "").trim();
    const d = String(last.desc ?? "").trim();
    return `${c} — ${d}`;
  }
  const code = lookupCodeForUserLevel(level);
  return `${code} — ${USER_LEVEL_LABELS[level]}`;
}

/** Levels that can use SELAR / staff ticket views (0–3). */
export const STAFF_USER_LEVELS: UserLevel[] = [
  "super_admin",
  "internal_admin",
  "external_admin",
  "agent",
];

/** Level 0–3 (kakitangan) — boleh assign ejen bawah (sama konsep Customers; bukan L4). */
export const USER_LEVELS_WITH_MANAGED_AGENTS: UserLevel[] = [
  "super_admin",
  "internal_admin",
  "external_admin",
  "agent",
];

/** Level 4–5 (end users); same class for tickets / AINA / hierarchy leaf users. */
export function isEndUserLevel(lvl: string | undefined): boolean {
  const l = coerceUserLevel(lvl);
  return l === "user" || l === "secondary_user";
}

/** True for Level 0–3 (staff). Uses coercion so API / select quirks still work. */
export function userLevelCanHaveManagedAgents(lvl: string | undefined): boolean {
  return !isEndUserLevel(lvl);
}

/** Normalize API value (e.g. legacy `admin` → internal_admin). Aligns with App\\Enums\\UserLevel::normalize. */
export function coerceUserLevel(level: string | undefined): UserLevel {
  if (level === "admin") return "internal_admin";
  const trimmed = String(level ?? "").trim();
  const l = trimmed
    .toLowerCase()
    .replace(/[\s-]+/g, "_")
    .replace(/_+/g, "_")
    .replace(/^_|_$/g, "");
  if (l && USER_LEVELS.includes(l as UserLevel)) return l as UserLevel;
  // Canonical snake_case + numeric tier (match App\\Enums\\UserLevel::normalize)
  if (l === "super_admin" || l === "superadmin" || l === "l0" || l === "level0" || l === "level_0" || l === "0" || l === "00")
    return "super_admin";
  if (
    l === "internal_admin" ||
    l === "internaladmin" ||
    l === "l1" ||
    l === "level1" ||
    l === "level_1" ||
    l === "1" ||
    l === "01"
  )
    return "internal_admin";
  if (l === "external_admin" || l === "externaladmin" || l === "l2" || l === "level2" || l === "level_2" || l === "2" || l === "02")
    return "external_admin";
  if (l === "agent" || l === "l3" || l === "level3" || l === "level_3" || l === "3" || l === "03") return "agent";
  if (l === "user" || l === "l4" || l === "level4" || l === "level_4" || l === "4" || l === "04") return "user";
  return "user";
}

/** Levels the actor may assign when creating/updating users (must match App\\Enums\\UserLevel). */
export function assignableUserLevelsForActor(actorLevel: string | undefined): UserLevel[] {
  const a = coerceUserLevel(actorLevel ?? "user");
  switch (a) {
    case "super_admin":
      return [...USER_LEVELS];
    case "internal_admin":
      return ["external_admin", "agent", "user", "secondary_user"];
    case "external_admin":
      return ["agent", "user", "secondary_user"];
    case "agent":
      return ["user", "secondary_user"];
    default:
      return [];
  }
}

/** Row from GET /api/users/agent-picklist (managed agents picker). */
export type AgentPicklistItem = {
  id: number;
  name: string;
  email: string;
  userLevel: UserLevel;
};

/** Ejen yang dilantik bagi satu pelanggan (pentadbir L0–L3). */
export type CustomerAgentAssignmentRow = {
  customerId: number;
  agentIds: number[];
};

export type UserDetail = {
  id: number;
  name: string;
  email: string;
  role: string;
  userLevel: UserLevel;
  userJenisPengguna?: string | null;
  customerCode?: string | null;
  /** Reports to (hierarchy). */
  managedByUserId?: number | null;
  /** Agent user IDs under this user when level is 0–3 (`managed_by_user_id` → this user). */
  managedAgentIds?: number[];
  /** Per customer: which agents (must report to this user + share customer). */
  customerAgentAssignments?: CustomerAgentAssignmentRow[];
  /** Admin/internal notes (catatan) — optional. */
  notes?: string | null;
  isActive: boolean;
  createdAt: string;
  updatedAt: string;
  roles: { id: number; name: string }[];
  roleIds: number[];
  customers: { id: number; customerCode: string; customerName: string }[];
  customerIds: number[];
};

/** POST /api/users — e-mel & kata laluan wajib (sejajar dengan StoreUserRequest). */
export type CreateUserInput = {
  name: string;
  email: string;
  password: string;
  roleIds?: number[];
  userLevel?: UserLevel;
  userJenisPengguna?: string | null;
  customerIds?: number[];
  managedAgentIds?: number[];
  customerAgentAssignments?: CustomerAgentAssignmentRow[];
  notes?: string | null;
  isActive?: boolean;
};

/** PUT /api/users/:id — medan pilihan / separa kemas kini (UpdateUserRequest). */
export type UpdateUserInput = {
  name?: string;
  email?: string;
  password?: string;
  roleIds?: number[];
  userLevel?: UserLevel;
  userJenisPengguna?: string | null;
  customerIds?: number[];
  managedAgentIds?: number[];
  customerAgentAssignments?: CustomerAgentAssignmentRow[];
  notes?: string | null;
  isActive?: boolean;
};

/** @deprecated Guna CreateUserInput (cipta) atau UpdateUserInput (kemas kini). */
export type UserInput = UpdateUserInput;

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
  /** Pratonton tiket dalaman (Kerisi) */
  systemName?: string;
  module?: string;
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

/** Log sync tiket dalaman → AI (bentuk sama seperti Desk365SyncLog). */
export type InternalTicketSyncLog = Desk365SyncLog;

/** MYFIS knowledge extract runs (schema / lookup / menu_access) from Knowledge admin UI. */
export type KnowledgeExtractSyncLog = {
  id: number;
  userId: number | null;
  section: string;
  triggeredBy: string;
  status: string;
  message: string | null;
  output: string | null;
  createdAt: string;
  user?: { id: number; name: string; email: string } | null;
};

export type TicketMonitoringLabelCount = { label: string; count: number };

export type TicketMonitoringSyncRow = {
  createdAt: string;
  status: string;
  totalTickets: number;
  uploaded: number;
  failed: number;
  message: string | null;
};

export type TicketMonitoringPayload = {
  generatedAt: string;
  internal: {
    total: number;
    open: number;
    unassigned: number;
    byStatus: TicketMonitoringLabelCount[];
    byPriority: TicketMonitoringLabelCount[];
    byModule: TicketMonitoringLabelCount[];
    openByAssignee: TicketMonitoringLabelCount[];
    createdLast7Days: number;
    closedLast7Days: number;
  };
  desk365Synced: {
    total: number;
    byStatus: TicketMonitoringLabelCount[];
    byModule: TicketMonitoringLabelCount[];
    byPriority: TicketMonitoringLabelCount[];
    openByAgent: TicketMonitoringLabelCount[];
  };
  chatActivity: {
    sessionsByUser: TicketMonitoringLabelCount[];
  };
  aiKnowledge: {
    desk365DocumentCount: number;
    desk365UploadedCount: number;
    internalDocumentCount: number;
    internalUploadedCount: number;
  };
  lastSync: {
    desk365: TicketMonitoringSyncRow | null;
    internal: TicketMonitoringSyncRow | null;
  };
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
  /** Human-readable status, used in KerisiChatView sidebar + prompts. */
  statusLabel?: string;
  /** Category / grouping label for monitoring; optional in API. */
  category?: string;
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
  customerName?: string | null;
  systemName?: string | null;
  module?: string | null;
  type?: "bugs" | "request" | "question" | null;
  priority: SupportTicketPriority;
  status: SupportTicketStatus;
  /** Lalai: benarkan AINA menjawab & tanya puas hati dalam perbualan */
  aiAssistanceEnabled?: boolean;
  aiAwaitingSatisfaction?: boolean;
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
  isAiMessage?: boolean;
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

/** Database explorer (live schema + rows) — API camelCase. */
export type DatabaseColumnInfo = {
  name: string;
  type: string;
  nullable: boolean;
  default: unknown;
  primaryKey: boolean;
};

export type DatabaseSchemaPayload = {
  columns: DatabaseColumnInfo[];
  primaryKeyColumns: string[];
};

export type DatabaseRowsPayload = {
  rows: Record<string, unknown>[];
  primaryKeyColumns: string[];
};
