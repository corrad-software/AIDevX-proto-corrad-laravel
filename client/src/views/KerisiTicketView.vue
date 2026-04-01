<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import AdminLayout from "@/layouts/AdminLayout.vue";
import MarkdownEditor from "@/components/MarkdownEditor.vue";
import { useToast } from "@/composables/useToast";
import { useAuthStore } from "@/stores/auth";
import {
  assignSupportTicket,
  closeSupportTicket,
  createSupportTicket,
  deleteSupportTicket,
  getSupportTicket,
  listAgentPicklist,
  listSupportTickets,
  postTicketAgentReplySuggest,
  rejectSupportTicketAi,
  replySupportTicket,
  updateSupportTicket,
} from "@/api/cms";
import { ensureCsrfCookie } from "@/api/client";
import {
  coerceUserLevel,
  type AgentPicklistItem,
  type CustomerLink,
  type SupportTicket,
  type SupportTicketMessage,
  type SupportTicketStatus,
} from "@/types";
import { markdownToSafeHtml } from "@/utils/markdown";
import { HELPDESK_TICKET_SYSTEM_LABEL } from "@/config/branding";
import {
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Copy,
  MessageSquare,
  PlusCircle,
  Reply,
  Save,
  Search,
  Sparkles,
  Ticket,
  UserCheck,
  XCircle,
} from "lucide-vue-next";

/** Paparan perbualan — peringkat pengguna + AINA */
type MessageRoleKind = "user" | "agent" | "internal_admin" | "external_admin" | "super_admin" | "aina";

const toast = useToast();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const rows = ref<SupportTicket[]>([]);
const loading = ref(false);
const page = ref(1);
const q = ref("");
const statusFilter = ref("");
const meta = ref<{ page?: number; limit?: number; total?: number; totalPages?: number } | null>(null);

const selected = ref<(SupportTicket & { messages?: SupportTicketMessage[] }) | null>(null);
const detailLoading = ref(false);
const replyText = ref("");
/** Populated by MarkdownEditor @mention picker — sent to API for notifications. */
const replyMentionedUserIds = ref<number[]>([]);
const replying = ref(false);
/** Staff: optional next status when sending reply (reply API), or for "status only" update (PATCH). */
const replyStatusChoice = ref<string>("");
const updatingStatus = ref(false);
const assignTo = ref<number | null>(null);
const assigning = ref(false);
const agents = ref<AgentPicklistItem[]>([]);
const agentsLoading = ref(false);
/** Wider list for @mention in replies (same manager team / all agents for L0–L1). */
const mentionAgents = ref<AgentPicklistItem[]>([]);
const showCreate = ref(false);
/** Selected row from `customer_links` when creating a ticket (customer + system). */
const createCustomerLinkId = ref<number | null>(null);

const createForm = ref({
  subject: "",
  description: "",
  customerName: "",
  systemName: "",
  module: "",
  type: "bugs" as "bugs" | "request" | "question",
  priority: "normal" as "low" | "normal" | "high" | "urgent",
  /** Lalai: aktif — AINA jawab dahulu (analisis/hints; mungkin silap terutama BI). */
  aiAssistanceEnabled: true,
});
let searchDebounce: ReturnType<typeof setTimeout> | null = null;

const actorLevel = computed(() => coerceUserLevel(auth.user?.userLevel));
const isLevel4User = computed(() => actorLevel.value === "user");
const canCreate = computed(() => actorLevel.value === "user" || actorLevel.value === "agent");
/** Staff who may use assign API (not Level 4). */
const canActAsTicketAssignee = computed(() =>
  ["internal_admin", "external_admin", "super_admin", "agent"].includes(actorLevel.value),
);
const isTicketAssignAdmin = computed(() =>
  ["internal_admin", "external_admin", "super_admin"].includes(actorLevel.value),
);
/** Show assign UI: admins always; agents only when ticket already has an assignee. */
const showAssignPanel = computed(() => {
  const s = selected.value;
  if (!s || !canActAsTicketAssignee.value) return false;
  if (isTicketAssignAdmin.value) return true;
  return Boolean(s.assignee?.id ?? s.assignedToUserId);
});
const canRespond = computed(() =>
  ["internal_admin", "external_admin", "super_admin", "agent"].includes(actorLevel.value),
);
const canEditOwn = computed(() => isLevel4User.value);
const canDeleteOwn = computed(() => isLevel4User.value);
/** Level 4: edit own ticket only while still `new` (before staff processes). */
const canRequestorEditTicket = computed(
  () =>
    canEditOwn.value &&
    selected.value &&
    selected.value.createdByUserId === auth.user?.id &&
    selected.value.status === "new",
);
/** Level 0 + Level 1: edit any ticket content (except closed). */
const canStaffAdminEditTicket = computed(
  () =>
    selected.value &&
    selected.value.status !== "closed" &&
    (actorLevel.value === "super_admin" || actorLevel.value === "internal_admin"),
);

/** Read-only labels for L4 ticket form (from profile / linked customer). */
const profileCustomerLabel = computed(() => auth.user?.customerDisplayName?.trim() || auth.user?.customerCode || "—");
const profileSystemLabel = computed(
  () => auth.user?.systemDisplayName?.trim() || HELPDESK_TICKET_SYSTEM_LABEL,
);

const ticketCustomerSystemOptions = computed(() => {
  const links = (auth.user?.customerLinks ?? []) as CustomerLink[];
  return links.map((l) => ({
    id: l.id,
    label: `${l.customerName}${l.systemName ? ` — ${l.systemName}` : ""}`,
    customerName: l.customerName,
    systemName: l.systemName?.trim() ?? "",
  }));
});

const selectedDescriptionHtml = computed(() => markdownToSafeHtml(selected.value?.description ?? ""));

/** Users available for @mention on the open ticket (pemohon, ejen ditugaskan, senarai ejen). */
const ticketMentionUsers = computed(() => {
  const s = selected.value;
  if (!s) return [];
  const map = new Map<number, { id: number; name: string }>();
  const add = (row: { id?: number; name?: string } | null | undefined) => {
    if (row?.id && row.name?.trim()) {
      map.set(row.id, { id: row.id, name: row.name.trim() });
    }
  };
  add(s.requestor);
  add(s.assignee);
  for (const a of mentionAgents.value) {
    map.set(a.id, { id: a.id, name: a.name });
  }
  const myId = auth.user?.id;
  if (myId != null) {
    map.delete(myId);
  }
  return [...map.values()].sort((a, b) => a.name.localeCompare(b.name));
});

/** Matches SupportTicketController::canMoveTo + ReplySupportTicketRequest (subset for reply). */
const STATUS_GRAPH: Record<SupportTicketStatus, SupportTicketStatus[]> = {
  new: ["assigned", "closed"],
  assigned: ["in_progress", "pending_requestor", "resolved", "closed"],
  in_progress: ["pending_requestor", "resolved", "closed"],
  pending_requestor: ["in_progress", "resolved", "closed"],
  resolved: ["closed", "in_progress"],
  closed: [],
};

const REPLY_ALLOWED = new Set<SupportTicketStatus>(["in_progress", "pending_requestor", "resolved", "closed"]);

function allowedReplyNextStatuses(current: SupportTicketStatus): SupportTicketStatus[] {
  return (STATUS_GRAPH[current] ?? []).filter((s) => REPLY_ALLOWED.has(s));
}

const STATUS_LABEL_MS: Record<SupportTicketStatus, string> = {
  new: "Baru",
  assigned: "Ditugaskan",
  in_progress: "Sedang diproses",
  pending_requestor: "Menunggu pemohon",
  resolved: "Selesai",
  closed: "Ditutup",
};

function statusLabelMs(s: SupportTicketStatus): string {
  return STATUS_LABEL_MS[s] ?? s;
}

const canShowStaffStatusControls = computed(
  () => canRespond.value && selected.value && selected.value.status !== "closed",
);

const canApplyStatusOnly = computed(() => {
  if (!canShowStaffStatusControls.value || !replyStatusChoice.value) return false;
  const next = replyStatusChoice.value as SupportTicketStatus;
  return next !== selected.value!.status;
});

function canTransitionTo(next: SupportTicketStatus): boolean {
  if (!selected.value) return false;
  const from = selected.value.status;
  return (STATUS_GRAPH[from] ?? []).includes(next);
}

const showQuickResolveBtn = computed(
  () => selected.value && selected.value.status !== "closed" && canTransitionTo("resolved"),
);
const showQuickCloseBtn = computed(
  () => selected.value && selected.value.status !== "closed" && canTransitionTo("closed"),
);

const AGENT_SUGGEST_LS = "kerisi.agentSuggestEnabled";

/** Lalai: aktif — cadangan ringkas AI untuk draf balasan ejen. */
const agentSuggestEnabled = ref(true);
const agentSuggestLoading = ref(false);
const agentSuggestText = ref("");
const agentSuggestError = ref("");
const agentSuggestDismissed = ref(false);
const agentSuggestRegeneratePrompt = ref("");

const shouldOfferAgentSuggest = computed(() => {
  const s = selected.value;
  if (!s || !agentSuggestEnabled.value) return false;
  if (!canRespond.value) return false;
  if (!s.assignedToUserId) return false;
  if (s.status === "closed") return false;
  return true;
});

const showAgentSuggestCard = computed(
  () => shouldOfferAgentSuggest.value && !agentSuggestDismissed.value,
);

function resetAgentSuggestPanel() {
  agentSuggestLoading.value = false;
  agentSuggestText.value = "";
  agentSuggestError.value = "";
  agentSuggestDismissed.value = false;
  agentSuggestRegeneratePrompt.value = "";
}

async function fetchAgentSuggest(regenerate: boolean) {
  if (!shouldOfferAgentSuggest.value || !selected.value?.id) return;
  agentSuggestLoading.value = true;
  agentSuggestError.value = "";
  try {
    await ensureCsrfCookie();
    const res = await postTicketAgentReplySuggest(selected.value.id, {
      regeneratePrompt: regenerate ? agentSuggestRegeneratePrompt.value.trim() || undefined : undefined,
    });
    agentSuggestText.value = res.data.suggestion ?? "";
    if (regenerate) {
      toast.success("New AI suggestion generated");
    }
  } catch (e) {
    agentSuggestText.value = "";
    agentSuggestError.value = e instanceof Error ? e.message : "Failed to fetch AI suggestion";
    toast.error(agentSuggestError.value);
  } finally {
    agentSuggestLoading.value = false;
  }
}

function dismissAgentSuggest() {
  agentSuggestDismissed.value = true;
}

async function rejectAiSuggestion() {
  dismissAgentSuggest();
  if (!selected.value) return;
  try {
    const res = await rejectSupportTicketAi(selected.value.id);
    const deletedAiMessages = Number(res.data.deletedAiMessages || 0);
    const nextTicket = res.data.ticket;
    selected.value = {
      ...selected.value,
      ...nextTicket,
      messages: (selected.value.messages ?? []).filter((m) => !m.isAiMessage),
    };
    toast.success(`AI rejected. ${deletedAiMessages} AI message(s) removed.`);
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to disable AI assistance");
  }
}

async function copyAgentSuggest() {
  const t = agentSuggestText.value.trim();
  if (!t) return;
  try {
    await navigator.clipboard.writeText(t);
    toast.success("Copied to clipboard");
  } catch {
    toast.error("Copy failed — please select text manually");
  }
}

function appendAgentSuggestToReply() {
  const t = agentSuggestText.value.trim();
  if (!t) return;
  replyText.value = replyText.value.trim() ? `${replyText.value.trim()}\n\n${t}` : t;
  toast.success("Added to reply editor");
}

function messageSenderRoleFromLevel(userLevel: string | null | undefined): MessageRoleKind {
  const n = coerceUserLevel(userLevel ?? "user");
  if (n === "user" || n === "secondary_user") return "user";
  if (n === "agent") return "agent";
  if (n === "internal_admin") return "internal_admin";
  if (n === "external_admin") return "external_admin";
  if (n === "super_admin") return "super_admin";
  return "user";
}

function messageRowRoleKind(m: SupportTicketMessage): MessageRoleKind {
  if (m.isAiMessage) return "aina";
  return messageSenderRoleFromLevel(m.user?.userLevel);
}

/** Gelembung AI jangan anggap sebagai mesej sendiri walaupun user_id = pemohon. */
function isOwnHumanMessage(m: SupportTicketMessage): boolean {
  if (m.isAiMessage) return false;
  const mid = m.userId != null ? Number(m.userId) : NaN;
  const aid = auth.user?.id != null ? Number(auth.user.id) : NaN;
  return Number.isFinite(mid) && Number.isFinite(aid) && mid === aid;
}

function messageSenderRoleLabel(kind: MessageRoleKind): string {
  switch (kind) {
    case "user":
      return "Pengguna";
    case "agent":
      return "Ejen";
    case "internal_admin":
      return "Pentadbir dalaman";
    case "external_admin":
      return "Pentadbir luaran";
    case "super_admin":
      return "Super Admin";
    case "aina":
      return "AINA (AI)";
  }
}

/** Peringkat paparan (selari Level 0–4 dalam docs). */
function messageRoleTier(kind: MessageRoleKind): string {
  switch (kind) {
    case "super_admin":
      return "L0";
    case "internal_admin":
      return "L1";
    case "external_admin":
      return "L2";
    case "agent":
      return "L3";
    case "user":
      return "L4";
    case "aina":
      return "AI";
  }
}

function messageSenderRoleLabelEn(kind: MessageRoleKind): string {
  switch (kind) {
    case "user":
      return "User";
    case "agent":
      return "Agent";
    case "internal_admin":
      return "Internal admin";
    case "external_admin":
      return "External admin";
    case "super_admin":
      return "Super admin";
    case "aina":
      return "AI assistant";
  }
}

const MESSAGE_ROLE_PALETTES: Record<
  Exclude<MessageRoleKind, "aina">,
  { own: string; other: string }
> = {
  user: {
    own: "ml-auto border-violet-400 bg-violet-100 text-slate-900 dark:border-violet-500 dark:bg-violet-950/60 dark:text-violet-50",
    other: "mr-auto border-slate-200 bg-slate-100 text-slate-900 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100",
  },
  agent: {
    own: "ml-auto border-sky-400 bg-sky-100 text-slate-900 dark:border-sky-600 dark:bg-sky-950/50 dark:text-sky-50",
    other: "mr-auto border-sky-200 bg-sky-50 text-slate-900 dark:border-sky-700 dark:bg-sky-950/40 dark:text-sky-100",
  },
  internal_admin: {
    own: "ml-auto border-teal-400 bg-teal-100 text-slate-900 dark:border-teal-500 dark:bg-teal-950/55 dark:text-teal-50",
    other: "mr-auto border-teal-200 bg-teal-50 text-slate-900 dark:border-teal-700 dark:bg-teal-950/35 dark:text-teal-100",
  },
  external_admin: {
    own: "ml-auto border-orange-400 bg-orange-100 text-slate-900 dark:border-orange-500 dark:bg-orange-950/50 dark:text-orange-50",
    other: "mr-auto border-orange-200 bg-orange-50 text-slate-900 dark:border-orange-700 dark:bg-orange-950/40 dark:text-orange-100",
  },
  super_admin: {
    own: "ml-auto border-fuchsia-500 bg-fuchsia-100 text-slate-900 dark:border-fuchsia-400 dark:bg-fuchsia-950/55 dark:text-fuchsia-50",
    other: "mr-auto border-fuchsia-200 bg-fuchsia-50 text-slate-900 dark:border-fuchsia-800 dark:bg-fuchsia-950/40 dark:text-fuchsia-100",
  },
};

function messageBubbleClasses(kind: MessageRoleKind, isOwn: boolean, isInternal: boolean): string {
  const base =
    "max-w-[min(100%,32rem)] rounded-2xl border px-3 py-2 text-sm shadow-sm break-words";
  if (isInternal) {
    return `${base} mx-auto border-amber-300 bg-amber-50 text-slate-900 dark:border-amber-700 dark:bg-amber-950/50 dark:text-amber-50`;
  }
  if (kind === "aina") {
    return `${base} mr-auto border-cyan-300 bg-cyan-50 text-slate-900 dark:border-cyan-600 dark:bg-cyan-950/45 dark:text-cyan-50`;
  }
  const side = isOwn ? "own" : "other";
  return `${base} ${MESSAGE_ROLE_PALETTES[kind][side]}`;
}

function roleBadgeClasses(kind: MessageRoleKind): string {
  switch (kind) {
    case "user":
      return "bg-violet-600/15 text-violet-800 dark:bg-violet-500/20 dark:text-violet-200";
    case "agent":
      return "bg-sky-600/15 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200";
    case "internal_admin":
      return "bg-teal-600/15 text-teal-900 dark:bg-teal-500/20 dark:text-teal-100";
    case "external_admin":
      return "bg-orange-600/15 text-orange-900 dark:bg-orange-500/20 dark:text-orange-100";
    case "super_admin":
      return "bg-fuchsia-600/15 text-fuchsia-900 dark:bg-fuchsia-500/20 dark:text-fuchsia-100";
    case "aina":
      return "bg-cyan-600/15 text-cyan-900 dark:bg-cyan-500/20 dark:text-cyan-100";
  }
}

/** Teks ringkas untuk salin / petikan (buang Markdown utama). */
function plainTextFromMarkdown(md: string): string {
  return String(md || "")
    .replace(/```[\s\S]*?```/g, " ")
    .replace(/`([^`]+)`/g, "$1")
    .replace(/!\[[^\]]*\]\([^)]+\)/g, "")
    .replace(/\[([^\]]+)\]\([^)]+\)/g, "$1")
    .replace(/^#{1,6}\s+/gm, "")
    .replace(/^\s*[-*+]\s+/gm, "")
    .replace(/^[>\s]*/gm, "")
    .replace(/[#>*_\-~]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

async function copyMessageText(m: SupportTicketMessage) {
  const text = plainTextFromMarkdown(m.message) || m.message;
  try {
    await navigator.clipboard.writeText(text);
    toast.success("Copied to clipboard");
  } catch {
    toast.error("Copy failed — please select text manually if needed");
  }
}

function quoteMessageInReply(m: SupportTicketMessage) {
  const who = m.isAiMessage ? "AINA" : m.user?.name || "—";
  const raw = plainTextFromMarkdown(m.message) || m.message;
  const excerpt = raw.length > 500 ? `${raw.slice(0, 500)}…` : raw;
  const lines = excerpt.split("\n");
  const quotedBody = lines.map((line) => `> ${line || " "}`).join("\n");
  const header = `> **${who}** · ${new Date(m.createdAt).toLocaleString()}`;
  const block = `${header}\n${quotedBody}\n\n`;
  replyText.value = replyText.value?.trim() ? `${replyText.value.trim()}\n\n${block}` : block;
  toast.success("Quote added to reply editor");
}

function showMessageActions(m: SupportTicketMessage): boolean {
  if (m.isInternal && !canRespond.value) return false;
  return true;
}

async function quickResolveTicket() {
  if (!selected.value || !canTransitionTo("resolved")) return;
  try {
    if (canRespond.value) {
      const res = await updateSupportTicket(selected.value.id, { status: "resolved" });
      selected.value = { ...selected.value, ...res.data };
    } else if (selected.value.createdByUserId === auth.user?.id) {
      await replySupportTicket(selected.value.id, {
        message: "Saya mengesahkan isu ini telah selesai.",
        status: "resolved",
      });
      await openDetail(selected.value);
    } else {
      toast.error("You are not allowed to set this status.");
      return;
    }
    toast.success("Status: Resolved");
    replyStatusChoice.value = "";
    await load();
    if (selected.value) await openDetail(selected.value);
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed");
  }
}

async function quickCloseTicket() {
  if (!selected.value || !canTransitionTo("closed")) return;
  try {
    if (canRespond.value) {
      const res = await updateSupportTicket(selected.value.id, { status: "closed" });
      selected.value = { ...selected.value, ...res.data };
    } else {
      await closeTicketNow();
      return;
    }
    toast.success("Ticket closed");
    replyStatusChoice.value = "";
    await load();
    if (selected.value) await openDetail(selected.value);
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to close");
  }
}

async function load() {
  loading.value = true;
  try {
    const params = new URLSearchParams({ page: String(page.value), limit: "20" });
    if (q.value.trim()) params.set("q", q.value.trim());
    if (statusFilter.value) params.set("status", statusFilter.value);
    const res = await listSupportTickets(`?${params.toString()}`);
    rows.value = res.data;
    meta.value = res.meta;
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to load tickets");
  } finally {
    loading.value = false;
  }
}

function syncCreateFormFromCustomerLink() {
  const id = createCustomerLinkId.value;
  const opts = ticketCustomerSystemOptions.value;
  if (id == null || !opts.length) return;
  const opt = opts.find((o) => o.id === id);
  if (opt) {
    createForm.value.customerName = opt.customerName;
    createForm.value.systemName = opt.systemName?.trim() || HELPDESK_TICKET_SYSTEM_LABEL;
  }
}

async function openDetail(ticket: SupportTicket) {
  detailLoading.value = true;
  try {
    const res = await getSupportTicket(ticket.id);
    const data = res.data;
    selected.value = data;
    replyText.value = "";
    replyMentionedUserIds.value = [];
    replyStatusChoice.value = "";
    const needAssignList =
      canActAsTicketAssignee.value &&
      (isTicketAssignAdmin.value || Boolean(data.assignee?.id ?? data.assignedToUserId));
    // Always refetch when opening detail — avoids empty dropdown after failed first load, stale cache, or level/hierarchy edge cases.
    if (needAssignList) {
      await loadAgents();
    }
    if (data.status !== "closed" && (canRespond.value || data.createdByUserId === auth.user?.id)) {
      await loadMentionAgents();
    } else {
      mentionAgents.value = [];
    }
    resetAgentSuggestPanel();
    if (
      agentSuggestEnabled.value &&
      canRespond.value &&
      data.assignedToUserId &&
      data.status !== "closed"
    ) {
      void fetchAgentSuggest(false);
    }
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to load ticket detail");
  } finally {
    detailLoading.value = false;
  }
}

async function loadAgents() {
  agentsLoading.value = true;
  try {
    await ensureCsrfCookie();
    const res = await listAgentPicklist();
    agents.value = Array.isArray(res.data) ? res.data : [];
  } catch (e) {
    agents.value = [];
    toast.error(e instanceof Error ? e.message : "Failed to load agents. Check connection or sign in again.");
  } finally {
    agentsLoading.value = false;
  }
}

async function loadMentionAgents() {
  try {
    await ensureCsrfCookie();
    const res = await listAgentPicklist(undefined, { forMention: true });
    mentionAgents.value = Array.isArray(res.data) ? res.data : [];
  } catch {
    mentionAgents.value = [];
  }
}

async function submitCreate() {
  try {
    const res = await createSupportTicket({
      subject: createForm.value.subject,
      description: createForm.value.description,
      customerName: createForm.value.customerName || undefined,
      systemName: createForm.value.systemName || undefined,
      module: createForm.value.module || undefined,
      type: createForm.value.type,
      priority: createForm.value.priority,
      aiAssistanceEnabled: createForm.value.aiAssistanceEnabled,
    });
    toast.success("Ticket created");
    showCreate.value = false;
    const opts = ticketCustomerSystemOptions.value;
    createCustomerLinkId.value = opts[0]?.id ?? null;
    createForm.value = {
      subject: "",
      description: "",
      customerName: opts[0]?.customerName ?? auth.user?.customerDisplayName?.trim() ?? auth.user?.customerCode ?? "",
      systemName: opts[0]?.systemName?.trim() || HELPDESK_TICKET_SYSTEM_LABEL,
      module: "",
      type: "bugs",
      priority: "normal",
      aiAssistanceEnabled: true,
    };
    await load();
    await openDetail(res.data);
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to create ticket");
  }
}

async function saveOwnTicket() {
  if (!selected.value) return;
  try {
    const res = await updateSupportTicket(selected.value.id, {
      subject: selected.value.subject,
      description: selected.value.description,
      module: selected.value.module ?? undefined,
      type: selected.value.type ?? undefined,
      customerName: selected.value.customerName ?? undefined,
      systemName: selected.value.systemName ?? undefined,
      priority: selected.value.priority,
      aiAssistanceEnabled: selected.value.aiAssistanceEnabled ?? true,
    });
    selected.value = { ...selected.value, ...res.data };
    toast.success("Ticket updated");
    await load();
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to update");
  }
}

async function saveStaffAdminTicket() {
  if (!selected.value) return;
  try {
    const res = await updateSupportTicket(selected.value.id, {
      subject: selected.value.subject,
      description: selected.value.description,
      module: selected.value.module ?? undefined,
      type: selected.value.type ?? undefined,
      customerName: selected.value.customerName ?? undefined,
      systemName: selected.value.systemName ?? undefined,
      priority: selected.value.priority,
    });
    selected.value = { ...selected.value, ...res.data };
    toast.success("Ticket updated");
    await load();
    await openDetail(selected.value);
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to update");
  }
}

async function removeOwnTicket() {
  if (!selected.value) return;
  try {
    await deleteSupportTicket(selected.value.id);
    toast.success("Ticket deleted");
    selected.value = null;
    await load();
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to delete");
  }
}

async function assignTicket() {
  if (!selected.value || !assignTo.value) return;
  assigning.value = true;
  try {
    const res = await assignSupportTicket(selected.value.id, assignTo.value);
    selected.value = { ...selected.value, ...res.data };
    toast.success("Ticket assigned");
    await load();
    await openDetail(selected.value);
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to assign");
  } finally {
    assigning.value = false;
  }
}

function hasReplyMarkdownContent(s: string): boolean {
  return Boolean(String(s || "").trim());
}

async function sendReply() {
  if (!selected.value || !hasReplyMarkdownContent(replyText.value)) return;
  replying.value = true;
  try {
    const next = replyStatusChoice.value.trim();
    const statusOpt: "in_progress" | "pending_requestor" | "resolved" | "closed" | undefined =
      next && REPLY_ALLOWED.has(next as SupportTicketStatus) ? (next as "in_progress" | "pending_requestor" | "resolved" | "closed") : undefined;
    await replySupportTicket(selected.value.id, {
      message: replyText.value.trim(),
      ...(replyMentionedUserIds.value.length ? { mentionedUserIds: [...replyMentionedUserIds.value] } : {}),
      ...(statusOpt ? { status: statusOpt } : {}),
    });
    toast.success(
      "Message sent. Relevant parties are notified via in-app notifications (and email if enabled). This conversation is not real-time — refresh to see latest replies.",
    );
    replyText.value = "";
    replyMentionedUserIds.value = [];
    replyStatusChoice.value = "";
    await openDetail(selected.value);
    await load();
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to send reply");
  } finally {
    replying.value = false;
  }
}

async function applyConversationStatusOnly() {
  if (!selected.value || !replyStatusChoice.value) {
    toast.error("Please choose a new status.");
    return;
  }
  const next = replyStatusChoice.value as SupportTicketStatus;
  if (next === selected.value.status) return;
  updatingStatus.value = true;
  try {
    const res = await updateSupportTicket(selected.value.id, { status: next });
    selected.value = { ...selected.value, ...res.data };
    toast.success("Status updated");
    replyStatusChoice.value = "";
    await load();
    await openDetail(selected.value);
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to update status");
  } finally {
    updatingStatus.value = false;
  }
}

async function closeTicketNow() {
  if (!selected.value) return;
  try {
    const res = await closeSupportTicket(selected.value.id);
    selected.value = { ...selected.value, ...res.data };
    toast.success("Ticket closed");
    await load();
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to close");
  }
}

function openCreateForm() {
  showCreate.value = true;
  const opts = ticketCustomerSystemOptions.value;
  if (opts.length) {
    createCustomerLinkId.value = opts[0].id;
    syncCreateFormFromCustomerLink();
  }
}

function applyDraftFromUserChat() {
  const fromChat = route.query.fromChat === "1";
  const forceCreate = route.query.create === "1";
  if (!fromChat && !forceCreate) return;
  const rawDraft = localStorage.getItem("kerisi_ticket_draft_from_chat");
  const querySubject = typeof route.query.subject === "string" ? route.query.subject : "";
  const querySource = typeof route.query.source === "string" ? route.query.source : "";
  let parsedSubject = "";
  let parsedBody = "";
  if (rawDraft) {
    try {
      const parsed = JSON.parse(rawDraft) as { subject?: string; body?: string; source?: string };
      parsedSubject = parsed.subject?.trim() || "";
      parsedBody = parsed.body?.trim() || "";
      if (!querySource && parsed.source) {
        createForm.value.module = parsed.source;
      }
    } catch {
      parsedBody = rawDraft;
    }
  }
  const draftBody = parsedBody.trim();
  if (!draftBody && !querySubject) return;
  showCreate.value = true;
  if (!createForm.value.subject.trim() || forceCreate) {
    createForm.value.subject = querySubject || parsedSubject || "Ticket from chat";
  }
  if (draftBody) {
    createForm.value.description = draftBody;
  }
  if (querySource) {
    createForm.value.module = querySource;
  }
  if (rawDraft) localStorage.removeItem("kerisi_ticket_draft_from_chat");
  router.replace({ path: route.path, query: {} });
}

onMounted(async () => {
  try {
    const raw = localStorage.getItem(AGENT_SUGGEST_LS);
    agentSuggestEnabled.value = raw === null || raw === "1" || raw === "true";
  } catch {
    agentSuggestEnabled.value = true;
  }
  await auth.refreshUser();
  const opts = ticketCustomerSystemOptions.value;
  if (opts.length) {
    createCustomerLinkId.value = opts[0].id;
    syncCreateFormFromCustomerLink();
  } else {
    createForm.value.customerName = auth.user?.customerDisplayName?.trim() || auth.user?.customerCode || "";
    createForm.value.systemName = auth.user?.systemDisplayName?.trim() || HELPDESK_TICKET_SYSTEM_LABEL;
  }
  // Level 4: show form by default; agents use explicit "Add ticket" (clearer UX).
  if (actorLevel.value === "user") {
    showCreate.value = true;
  }
  applyDraftFromUserChat();
  await load();
});

watch(canActAsTicketAssignee, async (v) => {
  if (v) {
    await loadAgents();
  }
});

watch(createCustomerLinkId, () => {
  syncCreateFormFromCustomerLink();
});

watch(statusFilter, async () => {
  page.value = 1;
  await load();
});

watch(q, () => {
  if (searchDebounce) clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    searchDebounce = null;
    page.value = 1;
    void load();
  }, 250);
});

watch(agentSuggestEnabled, (on) => {
  try {
    localStorage.setItem(AGENT_SUGGEST_LS, on ? "1" : "0");
  } catch {
    /* ignore */
  }
  if (!on) {
    agentSuggestLoading.value = false;
    return;
  }
  resetAgentSuggestPanel();
  if (
    selected.value &&
    canRespond.value &&
    selected.value.assignedToUserId &&
    selected.value.status !== "closed"
  ) {
    void fetchAgentSuggest(false);
  }
});
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-7xl px-4 py-6">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-slate-100">
          <Ticket class="h-6 w-6 text-[var(--accent-600)]" />
          Ticket
        </h1>
        <div v-if="canCreate" class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-lg bg-[var(--accent-600)] px-3 py-2 text-sm font-medium text-white shadow-sm hover:opacity-95"
            @click="openCreateForm()"
          >
            <PlusCircle class="h-4 w-4" />
            Add ticket
          </button>
          <button
            v-if="showCreate"
            type="button"
            class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200"
            @click="showCreate = false"
          >
            Hide form
          </button>
        </div>
      </div>

      <div v-if="canCreate && showCreate" class="mb-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
        <h2 class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-200">New Ticket</h2>
        <div class="mb-3 rounded-md border border-slate-100 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
          <p><span class="font-semibold text-slate-800 dark:text-slate-100">Customer:</span> {{ profileCustomerLabel }}</p>
          <p class="mt-1"><span class="font-semibold text-slate-800 dark:text-slate-100">System name:</span> {{ profileSystemLabel }}</p>
          <p class="mt-1 text-slate-500">You can adjust the values below if needed for this ticket.</p>
        </div>
        <div class="grid gap-3 md:grid-cols-2">
          <input v-model="createForm.subject" class="rounded border px-3 py-2 text-sm dark:bg-slate-950" placeholder="Subject" />
          <template v-if="ticketCustomerSystemOptions.length > 0">
            <div class="md:col-span-2">
              <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">Pelanggan & sistem (ikut tetapan akaun)</label>
              <select
                v-model.number="createCustomerLinkId"
                class="w-full rounded border px-3 py-2 text-sm dark:bg-slate-950"
              >
                <option v-for="o in ticketCustomerSystemOptions" :key="o.id" :value="o.id">{{ o.label }}</option>
              </select>
            </div>
          </template>
          <template v-else>
            <input v-model="createForm.customerName" class="rounded border px-3 py-2 text-sm dark:bg-slate-950" placeholder="Customer" />
            <input v-model="createForm.systemName" class="rounded border px-3 py-2 text-sm dark:bg-slate-950" placeholder="System Name" />
          </template>
          <input v-model="createForm.module" class="rounded border px-3 py-2 text-sm dark:bg-slate-950" placeholder="Module (e.g. Cashbook)" />
          <select v-model="createForm.type" class="rounded border px-3 py-2 text-sm dark:bg-slate-950">
            <option value="bugs">Bugs</option>
            <option value="request">Request</option>
            <option value="question">Question</option>
          </select>
          <select v-model="createForm.priority" class="rounded border px-3 py-2 text-sm dark:bg-slate-950">
            <option value="low">Low</option>
            <option value="normal">Normal</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </select>
          <div class="md:col-span-2 rounded-lg border border-cyan-100 bg-cyan-50/50 p-3 dark:border-cyan-900/40 dark:bg-cyan-950/20">
            <label class="flex cursor-pointer items-start gap-3">
              <input v-model="createForm.aiAssistanceEnabled" type="checkbox" class="mt-1 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500" />
              <div>
                <span class="text-sm font-medium text-slate-800 dark:text-slate-100">AINA assistance (AI)</span>
                <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">
                  If enabled, AINA replies first with analysis and guidance (not normal chat). AI may be wrong, especially for BI cases.
                  Then AINA asks for satisfaction confirmation; if yes, ticket can be closed, otherwise continue with human support.
                </p>
                <button
                  v-if="createForm.aiAssistanceEnabled"
                  type="button"
                  class="mt-2 inline-flex items-center gap-1.5 rounded-md border border-rose-300 bg-rose-50 px-2.5 py-1 text-[11px] font-medium text-rose-700 hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/30 dark:text-rose-200"
                  @click="createForm.aiAssistanceEnabled = false"
                >
                  <XCircle class="h-3.5 w-3.5" />
                  Cancel AI suggestion
                </button>
              </div>
            </label>
          </div>
          <div class="md:col-span-2">
            <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">Issue description (full editor — Markdown, emoji, image)</label>
            <MarkdownEditor
              v-model="createForm.description"
              :rows="8"
              :enable-image-upload="true"
              :expanded-emoji-picker="true"
              placeholder="Describe your issue..."
            />
          </div>
        </div>
        <button class="mt-3 inline-flex items-center gap-2 rounded bg-blue-600 px-3 py-2 text-sm font-medium text-white" @click="submitCreate">
          <Save class="h-4 w-4" />
          Submit Ticket
        </button>
      </div>

      <div class="grid gap-4 lg:grid-cols-[380px_1fr]">
        <div class="rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
          <div class="flex flex-wrap items-center gap-2 border-b p-3">
            <div class="relative min-w-0 flex-1">
              <Search class="absolute left-2 top-2.5 h-4 w-4 text-slate-400" />
              <input v-model="q" class="w-full rounded border pl-8 pr-2 py-2 text-sm dark:bg-slate-950" placeholder="Search ticket..." @keyup.enter="load" />
            </div>
            <select v-model="statusFilter" class="shrink-0 rounded border px-2 py-2 text-sm dark:bg-slate-950" @change="load">
              <option value="">All</option>
              <option value="new">New</option>
              <option value="assigned">Assigned</option>
              <option value="in_progress">In progress</option>
              <option value="pending_requestor">Pending requestor</option>
              <option value="resolved">Resolved</option>
              <option value="closed">Closed</option>
            </select>
          </div>
          <div class="max-h-[70vh] min-h-[220px] overflow-y-auto">
            <div v-if="loading" class="p-4 text-sm text-slate-500">Loading...</div>
            <button
              v-for="t in rows"
              :key="t.id"
              class="w-full border-b px-3 py-3 text-left hover:bg-slate-50 dark:hover:bg-slate-800"
              :class="selected?.id === t.id ? 'bg-slate-50 dark:bg-slate-800' : ''"
              @click="openDetail(t)"
            >
              <p class="truncate text-sm font-medium">{{ t.ticketNumber }} · {{ t.subject }}</p>
              <p class="mt-0.5 truncate text-xs text-slate-500">{{ t.customerName || "-" }} · {{ t.systemName || "-" }}</p>
              <p class="mt-0.5 text-xs text-slate-500">{{ t.status }} · {{ t.priority }}</p>
            </button>
            <div v-if="!loading && rows.length === 0" class="p-4 text-sm text-slate-500">
              <p>No tickets yet.</p>
              <p v-if="canCreate" class="mt-2 text-xs text-slate-400">
                Use the <strong class="text-slate-600 dark:text-slate-300">Add ticket</strong> button at the top-right.
              </p>
              <p v-if="canCreate && showCreate" class="mt-2 text-xs text-slate-400">Fill in the form above — your ticket will appear here.</p>
            </div>
          </div>
          <div v-if="meta && (meta.totalPages ?? 1) > 1" class="flex items-center justify-between border-t p-2 text-xs">
            <span>{{ meta.page }}/{{ meta.totalPages }}</span>
            <div class="flex gap-1">
              <button class="rounded border p-1" :disabled="(meta.page ?? 1) <= 1" @click="page = Math.max(1, page - 1); load()"><ChevronLeft class="h-4 w-4" /></button>
              <button class="rounded border p-1" :disabled="(meta.page ?? 1) >= (meta.totalPages ?? 1)" @click="page = Math.min(meta.totalPages ?? 1, page + 1); load()"><ChevronRight class="h-4 w-4" /></button>
            </div>
          </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
          <div v-if="detailLoading" class="text-sm text-slate-500">Loading detail...</div>
          <div v-else-if="!selected" class="text-sm text-slate-500">Select a ticket.</div>
          <div v-else class="space-y-3">
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="text-sm text-slate-500">{{ selected.ticketNumber }}</p>
                <h2 class="text-lg font-semibold">{{ selected.subject }}</h2>
                <div class="ticket-desc-preview prose prose-sm max-w-none text-slate-700 dark:prose-invert" v-html="selectedDescriptionHtml" />
              </div>
              <span class="rounded border px-2 py-1 text-xs">{{ selected.status }}</span>
            </div>

            <div class="grid gap-2 md:grid-cols-3 text-xs text-slate-500">
              <div>Customer: <span class="font-medium text-slate-700">{{ selected.customerName || "-" }}</span></div>
              <div>System: <span class="font-medium text-slate-700">{{ selected.systemName || "-" }}</span></div>
              <div>Priority: <span class="font-medium text-slate-700">{{ selected.priority }}</span></div>
              <div>Module: <span class="font-medium text-slate-700">{{ selected.module || "-" }}</span></div>
              <div>Type: <span class="font-medium text-slate-700">{{ selected.type || "-" }}</span></div>
              <div>Assignee: <span class="font-medium text-slate-700">{{ selected.assignee?.name || "-" }}</span></div>
              <div class="md:col-span-3">
                AINA assistance:
                <span class="font-medium text-slate-700 dark:text-slate-200">{{
                  selected.aiAssistanceEnabled !== false ? "Enabled" : "Disabled"
                }}</span>
                <span
                  v-if="selected.aiAwaitingSatisfaction"
                  class="ml-2 rounded-full bg-cyan-100 px-2 py-0.5 text-[10px] font-semibold text-cyan-900 dark:bg-cyan-900/40 dark:text-cyan-100"
                >
                  Waiting for satisfaction reply
                </span>
              </div>
            </div>

            <div v-if="showAssignPanel" class="rounded border p-3">
              <p class="mb-2 text-xs font-semibold text-slate-600">Assign to Agent</p>
              <p v-if="agentsLoading" class="mb-2 text-xs text-slate-500">Loading agent list...</p>
              <p
                v-else-if="agents.length === 0"
                class="mb-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
              >
                No available agent to select. Make sure at least one
                <strong>Level 3 (Agent)</strong> user exists in
                <RouterLink class="font-semibold underline" to="/admin/platform/identity/users">Users</RouterLink>.
                If you use <code class="rounded bg-white/80 px-1 dark:bg-slate-900">php artisan serve</code> on a non-8090 port, set
                <code class="rounded bg-white/80 px-1 dark:bg-slate-900">VITE_PROXY_TARGET</code> in <code class="rounded bg-white/80 px-1 dark:bg-slate-900">client/.env</code>
                to match Laravel's port.
              </p>
              <div class="flex flex-wrap gap-2">
                <select
                  v-model="assignTo"
                  class="min-w-0 flex-1 rounded border px-2 py-2 text-sm dark:bg-slate-950"
                  :disabled="agentsLoading"
                >
                  <option :value="null">Select agent</option>
                  <option v-for="a in agents" :key="a.id" :value="a.id">{{ a.name }} ({{ a.email }})</option>
                </select>
                <button
                  type="button"
                  class="rounded border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                  :disabled="agentsLoading"
                  @click="loadAgents"
                >
                  Reload
                </button>
                <button
                  class="inline-flex items-center gap-1 rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50"
                  :disabled="assigning || !assignTo || agentsLoading || agents.length === 0"
                  @click="assignTicket"
                >
                  <UserCheck class="h-4 w-4" /> Assign
                </button>
              </div>
            </div>

            <div v-if="canStaffAdminEditTicket" class="rounded border border-violet-200 bg-violet-50/40 p-3 dark:border-violet-900 dark:bg-violet-950/20">
              <p class="mb-2 text-xs font-semibold text-violet-800 dark:text-violet-200">Edit ticket (Internal Admin / Super Admin)</p>
              <input v-model="selected.subject" class="mb-2 w-full rounded border px-2 py-2 text-sm dark:bg-slate-950" />
              <div class="mb-2 grid gap-2 md:grid-cols-2">
                <input v-model="selected.customerName" class="rounded border px-2 py-2 text-sm dark:bg-slate-950" placeholder="Customer" />
                <input v-model="selected.systemName" class="rounded border px-2 py-2 text-sm dark:bg-slate-950" placeholder="System Name" />
                <input v-model="selected.module" class="rounded border px-2 py-2 text-sm dark:bg-slate-950" placeholder="Module" />
                <select v-model="selected.type" class="rounded border px-2 py-2 text-sm dark:bg-slate-950">
                  <option value="bugs">Bugs</option>
                  <option value="request">Request</option>
                  <option value="question">Question</option>
                </select>
                <select v-model="selected.priority" class="rounded border px-2 py-2 text-sm dark:bg-slate-950">
                  <option value="low">Low</option>
                  <option value="normal">Normal</option>
                  <option value="high">High</option>
                  <option value="urgent">Urgent</option>
                </select>
              </div>
              <label class="mb-1 block text-xs font-medium text-violet-900 dark:text-violet-200">Description (full editor — same as create ticket)</label>
              <MarkdownEditor
                v-model="selected.description"
                :rows="8"
                :enable-image-upload="true"
                :expanded-emoji-picker="true"
                placeholder="Description"
              />
              <button type="button" class="mt-2 rounded bg-violet-700 px-3 py-1.5 text-xs text-white hover:bg-violet-800" @click="saveStaffAdminTicket">
                Save admin changes
              </button>
            </div>

            <div v-if="canRequestorEditTicket" class="rounded border p-3">
              <p class="mb-2 text-xs font-semibold text-slate-600">Edit your ticket</p>
              <input v-model="selected.subject" class="mb-2 w-full rounded border px-2 py-2 text-sm dark:bg-slate-950" />
              <div class="grid gap-2 md:grid-cols-2 mb-2">
                <input v-model="selected.customerName" class="rounded border px-2 py-2 text-sm dark:bg-slate-950" placeholder="Customer" />
                <input v-model="selected.systemName" class="rounded border px-2 py-2 text-sm dark:bg-slate-950" placeholder="System Name" />
                <select v-model="selected.type" class="rounded border px-2 py-2 text-sm dark:bg-slate-950">
                  <option value="bugs">Bugs</option>
                  <option value="request">Request</option>
                  <option value="question">Question</option>
                </select>
              </div>
              <label class="mb-2 flex cursor-pointer items-start gap-2 rounded border border-cyan-100 bg-cyan-50/40 p-2 text-xs dark:border-cyan-900/40 dark:bg-cyan-950/20">
                <input
                  v-model="selected.aiAssistanceEnabled"
                  type="checkbox"
                  class="mt-0.5 rounded border-slate-300 text-cyan-600"
                />
                <span class="text-slate-700 dark:text-slate-300">
                  <span class="font-semibold">AINA assistance (AI)</span>
                  — enable for auto replies and satisfaction flow (default: on).
                </span>
              </label>
              <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">Description (full editor — same as create ticket)</label>
              <MarkdownEditor
                v-model="selected.description"
                :rows="8"
                :enable-image-upload="true"
                :expanded-emoji-picker="true"
                placeholder="Describe your issue..."
              />
              <div class="mt-2 flex gap-2">
                <button class="rounded bg-slate-900 px-3 py-1.5 text-xs text-white" @click="saveOwnTicket">Save</button>
                <button v-if="canDeleteOwn && selected.status === 'new'" class="rounded border border-rose-300 px-3 py-1.5 text-xs text-rose-600" @click="removeOwnTicket">Delete</button>
              </div>
            </div>

            <div class="rounded border p-3">
              <div class="mb-2">
                <p class="text-xs font-semibold text-slate-600 dark:text-slate-300">Conversation</p>
                <p class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">
                  Your messages appear on the <strong>right</strong>; others on the left. Colors &amp; labels follow tier (L0–L4).
                </p>
              </div>
              <div class="mb-3 max-h-[28rem] space-y-3 overflow-y-auto px-0.5">
                <template v-for="m in selected.messages || []" :key="m.id">
                  <div
                    v-if="!(m.isInternal && !canRespond)"
                    class="flex w-full"
                    :class="
                      m.isInternal && canRespond
                        ? 'justify-center'
                        : isOwnHumanMessage(m)
                          ? 'justify-end'
                          : 'justify-start'
                    "
                  >
                    <div
                      :class="
                        messageBubbleClasses(
                          messageRowRoleKind(m),
                          isOwnHumanMessage(m),
                          Boolean(m.isInternal && canRespond),
                        )
                      "
                    >
                    <div class="mb-1 flex flex-wrap items-center gap-2">
                      <span class="font-medium text-slate-900 dark:text-slate-50">{{
                        m.isAiMessage ? "AINA" : m.user?.name || "—"
                      }}</span>
                      <span
                        class="rounded border px-1.5 py-0.5 font-mono text-[10px] font-bold tabular-nums"
                        :class="roleBadgeClasses(messageRowRoleKind(m))"
                        title="Tier / peringkat"
                      >
                        {{ messageRoleTier(messageRowRoleKind(m)) }}
                      </span>
                      <span
                        v-if="messageRowRoleKind(m) !== 'aina'"
                        class="rounded-full px-2 py-0.5 text-[10px] font-semibold tracking-wide"
                        :class="roleBadgeClasses(messageRowRoleKind(m))"
                        :title="messageSenderRoleLabelEn(messageRowRoleKind(m))"
                      >
                        {{ messageSenderRoleLabel(messageRowRoleKind(m)) }}
                        <span class="ml-1 font-normal opacity-80"
                          >({{ messageSenderRoleLabelEn(messageRowRoleKind(m)) }})</span
                        >
                      </span>
                      <span
                        v-else
                        class="rounded-full px-2 py-0.5 text-[10px] font-semibold tracking-wide"
                        :class="roleBadgeClasses('aina')"
                        title="AI assistant"
                      >
                        AINA (AI)
                      </span>
                      <span
                        v-if="m.isInternal && canRespond"
                        class="rounded bg-amber-500/20 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900 dark:text-amber-200"
                      >
                        Internal note
                      </span>
                      <span class="text-xs text-slate-500 dark:text-slate-400">{{ new Date(m.createdAt).toLocaleString() }}</span>
                    </div>
                    <div
                      class="ticket-msg-md prose prose-sm max-w-none text-slate-800 dark:prose-invert dark:text-slate-100"
                      v-html="markdownToSafeHtml(m.message)"
                    />
                    <div
                      v-if="showMessageActions(m)"
                      class="mt-2 flex flex-wrap gap-1 border-t border-black/5 pt-2 dark:border-white/10"
                    >
                      <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded border border-slate-200 bg-white px-2 py-1 text-[10px] font-medium text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
                        @click="copyMessageText(m)"
                      >
                        <Copy class="h-3 w-3" /> Copy
                      </button>
                      <button
                        v-if="(canRespond || selected.createdByUserId === auth.user?.id) && selected.status !== 'closed'"
                        type="button"
                        class="inline-flex items-center gap-1 rounded border border-slate-200 bg-white px-2 py-1 text-[10px] font-medium text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
                        @click="quoteMessageInReply(m)"
                      >
                        <Reply class="h-3 w-3" /> Quote & reply
                      </button>
                    </div>
                    </div>
                  </div>
                </template>
              </div>

              <div
                v-if="(canRespond || selected.createdByUserId === auth.user?.id) && selected.status !== 'closed'"
                class="mb-3 flex flex-wrap gap-2 border-t border-slate-200 pt-3 dark:border-slate-600"
              >
                <button
                  v-if="showQuickResolveBtn"
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-500/60 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-900 hover:bg-emerald-100 dark:bg-emerald-950/50 dark:text-emerald-100 dark:hover:bg-emerald-900/60"
                  @click="quickResolveTicket"
                >
                  <CheckCircle2 class="h-4 w-4" />
                  Mark resolved
                </button>
                <button
                  v-if="showQuickCloseBtn"
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-slate-400 bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-200 dark:border-slate-500 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                  @click="quickCloseTicket"
                >
                  <XCircle class="h-4 w-4" />
                  Close ticket
                </button>
                <button
                  v-if="showAgentSuggestCard && agentSuggestText.trim()"
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-500/60 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-900 hover:bg-emerald-100 dark:bg-emerald-950/50 dark:text-emerald-100 dark:hover:bg-emerald-900/60"
                  @click="appendAgentSuggestToReply"
                >
                  <Sparkles class="h-4 w-4" />
                  Accept AI
                </button>
                <button
                  v-if="showAgentSuggestCard"
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-800 hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-200 dark:hover:bg-rose-900/60"
                  @click="rejectAiSuggestion"
                >
                  <XCircle class="h-4 w-4" />
                  Reject AI
                </button>
              </div>

              <div v-if="canShowStaffStatusControls" class="mb-3 flex flex-wrap items-end gap-2">
                <div class="min-w-[200px] flex-1">
                  <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">Tukar status tiket</label>
                  <select
                    v-model="replyStatusChoice"
                    class="w-full rounded border border-slate-300 bg-white px-2 py-2 text-sm dark:border-slate-600 dark:bg-slate-950"
                  >
                    <option value="">— Auto on send (system default) —</option>
                    <option v-for="s in allowedReplyNextStatuses(selected.status)" :key="s" :value="s">
                      {{ statusLabelMs(s) }}
                    </option>
                  </select>
                </div>
                <button
                  type="button"
                  class="rounded border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                  :disabled="updatingStatus || !canApplyStatusOnly"
                  @click="applyConversationStatusOnly"
                >
                  Update status only
                </button>
              </div>
              <div v-if="(canRespond || selected.createdByUserId === auth.user?.id) && selected.status !== 'closed'" class="space-y-2">
                <div
                  v-if="canRespond"
                  class="rounded-lg border border-indigo-200/80 bg-indigo-50/50 px-3 py-2.5 dark:border-indigo-800/60 dark:bg-indigo-950/30"
                >
                  <label class="flex cursor-pointer items-start gap-2 text-xs text-slate-700 dark:text-slate-200">
                    <input
                      v-model="agentSuggestEnabled"
                      type="checkbox"
                      class="mt-0.5 rounded border-slate-300 text-indigo-600"
                    />
                    <span>
                      <span class="inline-flex items-center gap-1 font-semibold text-indigo-900 dark:text-indigo-100">
                        <Sparkles class="h-3.5 w-3.5 shrink-0" />
                        AI suggestion for reply
                      </span>
                      <span class="block text-[11px] font-normal text-slate-600 dark:text-slate-400">
                        Enabled by default. Turn on to generate a draft summary (edit before sending). Requires a ticket
                        <strong>already assigned</strong> to an agent.
                      </span>
                    </span>
                  </label>
                  <p
                    v-if="agentSuggestEnabled && !selected.assignedToUserId"
                    class="mt-2 text-[11px] text-slate-600 dark:text-slate-400"
                  >
                    No assigned agent yet — assign the ticket above to enable suggestions.
                  </p>
                </div>

                <div
                  v-if="showAgentSuggestCard"
                  class="space-y-2 rounded-lg border border-emerald-200/90 bg-emerald-50/40 p-3 dark:border-emerald-800/50 dark:bg-emerald-950/25"
                >
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs font-semibold text-emerald-900 dark:text-emerald-100">AI suggestion (draft)</p>
                    <button
                      type="button"
                      class="text-[11px] font-medium text-slate-600 underline decoration-slate-400 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200"
                      @click="dismissAgentSuggest"
                    >
                      Cancel suggestion
                    </button>
                  </div>
                  <p class="text-[11px] text-slate-600 dark:text-slate-400">
                    Review and edit before sending. This is not an official automatic reply to the requestor.
                  </p>
                  <div
                    v-if="agentSuggestLoading"
                    class="rounded border border-emerald-200/60 bg-white/80 px-2 py-6 text-center text-xs text-slate-500 dark:border-emerald-900/40 dark:bg-slate-900/40"
                  >
                    Generating suggestion...
                  </div>
                  <div
                    v-else-if="agentSuggestError && !agentSuggestText"
                    class="rounded border border-rose-200 bg-rose-50 px-2 py-2 text-xs text-rose-800 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-200"
                  >
                    {{ agentSuggestError }}
                  </div>
                  <div
                    v-else-if="agentSuggestText"
                    class="ticket-msg-md prose prose-sm max-w-none rounded border border-emerald-100 bg-white px-3 py-2 text-slate-800 dark:border-emerald-900/40 dark:bg-slate-900 dark:prose-invert"
                    v-html="markdownToSafeHtml(agentSuggestText)"
                  />
                  <div class="flex flex-wrap gap-2">
                    <button
                      type="button"
                      class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-1.5 text-[11px] font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                      :disabled="agentSuggestLoading || !agentSuggestText.trim()"
                      @click="copyAgentSuggest"
                    >
                      <Copy class="h-3.5 w-3.5" /> Copy
                    </button>
                    <button
                      type="button"
                      class="inline-flex items-center gap-1 rounded border border-emerald-600/50 bg-emerald-600 px-2.5 py-1.5 text-[11px] font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                      :disabled="agentSuggestLoading || !agentSuggestText.trim()"
                      @click="appendAgentSuggestToReply"
                    >
                      Accept suggestion
                    </button>
                    <button
                      type="button"
                      class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-1.5 text-[11px] font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200"
                      :disabled="agentSuggestLoading"
                      @click="fetchAgentSuggest(true)"
                    >
                      Regenerate
                    </button>
                  </div>
                  <div>
                    <label class="mb-1 block text-[10px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400"
                      >Prompt for another suggestion (optional)</label
                    >
                    <textarea
                      v-model="agentSuggestRegeneratePrompt"
                      rows="2"
                      class="w-full rounded border border-slate-300 bg-white px-2 py-1.5 text-xs text-slate-800 placeholder:text-slate-400 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
                      placeholder="Example: Shorter tone / customer-friendly / emphasize validation steps..."
                    />
                    <p class="mt-1 text-[10px] text-slate-500">
                      Fill this field (if needed), then click <code class="rounded bg-slate-200/80 px-1 dark:bg-slate-800">Regenerate</code>.
                    </p>
                  </div>
                </div>

                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">
                  Reply — <strong>rich editor</strong> (Markdown, Bold/Italic, emoji, image, Preview) — same as the
                  <em>New ticket</em> form. Type <code class="rounded bg-slate-100 px-1 text-[10px] dark:bg-slate-800">@</code> to mention
                  staff.
                </label>
                <MarkdownEditor
                  v-model="replyText"
                  v-model:mentioned-user-ids="replyMentionedUserIds"
                  :mention-users="ticketMentionUsers"
                  :rows="8"
                  :enable-image-upload="true"
                  :expanded-emoji-picker="true"
                  placeholder="Type your reply… — use Write / Preview toolbar (rich format, same as creating a ticket)"
                />
                <div class="flex flex-wrap gap-2">
                  <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-lg bg-blue-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-50"
                    :disabled="replying || !hasReplyMarkdownContent(replyText)"
                    title="Hantar mesej (simpan &amp; notifikasi)"
                    @click="sendReply"
                  >
                    <MessageSquare class="h-4 w-4" />
                    <span class="flex flex-col items-start leading-tight">
                      <span>Send</span>
                      <span class="text-[10px] font-normal opacity-90">Hantar</span>
                    </span>
                  </button>
                  <button
                    v-if="showQuickCloseBtn"
                    type="button"
                    class="inline-flex items-center gap-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                    title="Tutup tiket"
                    @click="quickCloseTicket"
                  >
                    <XCircle class="h-4 w-4" />
                    <span class="flex flex-col items-start leading-tight">
                      <span>Close</span>
                      <span class="text-[10px] font-normal opacity-90">Tutup</span>
                    </span>
                  </button>
                </div>
              </div>
              <p v-else-if="selected.status === 'closed'" class="text-xs text-slate-500">Ticket is closed — no new replies.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

<style scoped>
.ticket-desc-preview :deep(p) {
  margin: 0.5rem 0;
  line-height: 1.6;
  color: rgb(51 65 85);
}
.ticket-desc-preview :deep(ul),
.ticket-desc-preview :deep(ol) {
  margin: 0.5rem 0;
  padding-left: 1.25rem;
}
.ticket-desc-preview :deep(a) {
  color: rgb(124 58 237);
  text-decoration: underline;
}
.ticket-desc-preview :deep(img) {
  max-width: 100%;
  height: auto;
  border-radius: 0.375rem;
}
.ticket-desc-preview :deep(strong) {
  font-weight: 600;
}
.ticket-desc-preview :deep(u) {
  text-decoration: underline;
}

.ticket-msg-md :deep(p) {
  margin: 0.35rem 0;
  line-height: 1.55;
}
.ticket-msg-md :deep(p:first-child) {
  margin-top: 0;
}
.ticket-msg-md :deep(p:last-child) {
  margin-bottom: 0;
}
.ticket-msg-md :deep(ul),
.ticket-msg-md :deep(ol) {
  margin: 0.35rem 0;
  padding-left: 1.25rem;
}
.ticket-msg-md :deep(a) {
  color: rgb(124 58 237);
  text-decoration: underline;
}
.ticket-msg-md :deep(img) {
  max-width: 100%;
  height: auto;
  border-radius: 0.375rem;
}
.ticket-msg-md :deep(pre) {
  overflow-x: auto;
  border-radius: 0.375rem;
  padding: 0.5rem;
  font-size: 0.75rem;
}
</style>
