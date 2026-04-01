<script setup lang="ts">
import { ref, nextTick, onMounted, computed, onBeforeUnmount, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import AdminLayout from "@/layouts/AdminLayout.vue";
import { useAuthStore } from "@/stores/auth";
import { useToast } from "@/composables/useToast";
import {
  newChatSession,
  sendChatMessage,
  getChatSession,
  getMyChatSessions,
  deleteChatSession,
  updateChatSession,
  toggleChatSessionFavorite,
  listChatFavorites,
  listAgentPicklist,
  listChatTickets,
  listSupportTickets,
  toggleChatMessageFavorite,
  searchChatMessages,
  getChatSuggestions,
} from "@/api/cms";
import { ensureCsrfCookie } from "@/api/client";
import { markdownToSafeHtml } from "@/utils/markdown";
import { getEcho, disconnectEcho } from "@/api/echo";
import type {
  ChatSession,
  ChatMessage,
  ChatSuggestion,
  Desk365TicketChat,
  AgentPicklistItem,
  SupportTicket,
} from "@/types";
import * as BRANDING from "@/config/branding";
import {
  Send,
  Plus,
  Trash2,
  MessageSquare,
  Bot,
  User,
  Loader2,
  Paperclip,
  X,
  Copy,
  Star,
  Search,
  Settings,
  Forward,
  Smile,
  ChevronDown,
  ChevronRight,
  FileText,
  Ticket,
  Link,
  ExternalLink,
  Image,
  UserPlus,
} from "lucide-vue-next";

const toast = useToast();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const sessions = ref<ChatSession[]>([]);
const activeSession = ref<ChatSession | null>(null);
const messages = ref<ChatMessage[]>([]);
const inputMessage = ref("");
const attachedFiles = ref<File[]>([]);
const isSending = ref(false);
const isLoadingSession = ref(false);
const messagesContainer = ref<HTMLElement | null>(null);
const fileInputRef = ref<HTMLInputElement | null>(null);
const isDragging = ref(false);
const previewUrls = new Map<string, string>();

const chatMode = ref<"solo" | "group">("solo");
const favorites = ref<{ id: number; message: ChatMessage; session: ChatSession | null }[]>([]);
const suggestions = ref<ChatSuggestion[]>([]);
const searchQuery = ref("");
const showSearch = ref(false);
const searchResults = ref<ChatMessage[] | null>(null);
const showSettings = ref(false);
const currentTicketContext = ref<Desk365TicketChat | null>(null);
const replyToMsg = ref<ChatMessage | null>(null);
const chatSettings = ref({ showDocs: true, showLinks: true, showMedia: true });
const paparanOpen = ref<Record<string, boolean>>({ docs: false, links: false, media: false });
const showGroupInvite = ref(false);
const availableAgents = ref<AgentPicklistItem[]>([]);
const invitedAgentIds = ref<number[]>([]);
const inviteAgentSearch = ref("");
const showAddGroupMember = ref(false);
const showSharePanel = ref(false);
const forwardDropdownMsg = ref<ChatMessage | null>(null);
const forwardSessionList = ref<ChatSession[]>([]);
const forwardSearch = ref("");
const desk365Tickets = ref<Desk365TicketChat[]>([]);
const internalTickets = ref<SupportTicket[]>([]);
const ticketsLoading = ref(false);
const showEmojiPicker = ref(false);
const quickEmojis = ["😀", "🙂", "👍", "🙏", "🎉", "🔥", "✅", "🤝"];
const mentionDropdownOpen = ref(false);
const mentionStartIndex = ref(0);
const mentionQuery = ref("");
const mentionSelectedIndex = ref(0);
const textareaRef = ref<HTMLTextAreaElement | null>(null);
const connectionError = ref<string | null>(null);
const isRetryingConnection = ref(false);

const ACCEPTED_TYPES = ".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp,.gif";
const MAX_FILE_SIZE = 4 * 1024 * 1024; // 4MB

const KERISI_MODULES = [
  "All Modules",
  "Cashbook", "Account Receivable", "Account Payable",
  "General Ledger", "Payroll", "Purchasing", "Vendor Portal",
  "Debtor Portal", "Credit Control", "Investment", "Loan",
  "Asset", "Budget", "Staff Portal", "Setup & Maintenance",
];
const selectedModule = ref("All Modules");

const SIDEBAR_PREVIEW_LIMIT = 4;
const CHAT_HISTORY_LOAD_MORE = 4;
const chatHistoryVisibleCount = ref(SIDEBAR_PREVIEW_LIMIT);

const CONNECTION_MSG =
  "Cannot reach the API. If you use Vite (http://localhost:5190), Laravel must be running for the proxy (default http://127.0.0.1:8090). Try: (1) from repo root run `composer dev`, or (2) `php artisan serve --port=8090` plus `npm --prefix client run dev`. If Laravel uses another port, set `VITE_PROXY_TARGET` in `client/.env` or the root `.env` and restart Vite. Alternatively open the app via Laravel only: build with `npm --prefix client run build:laravel` then use your Laravel URL (e.g. http://localhost:8090).";

function isConnectionError(e: unknown): boolean {
  const msg = e instanceof Error ? e.message : String(e);
  return msg === "Load failed" || msg === "Failed to fetch";
}

function handleConnectionError(e: unknown): string {
  connectionError.value = CONNECTION_MSG;
  return isConnectionError(e) ? CONNECTION_MSG : (e instanceof Error ? e.message : "Request failed");
}

async function retryConnection() {
  connectionError.value = null;
  isRetryingConnection.value = true;
  try {
    await ensureCsrfCookie(true);
    const res = await getMyChatSessions();
    sessions.value = res.data;
    loadFavorites();
    loadSuggestions();
    toast.success("Connected.");
  } catch (e) {
    toast.error(handleConnectionError(e));
  } finally {
    isRetryingConnection.value = false;
  }
}

onMounted(async () => {
  await loadSessions();
  await loadTicketLists();
  loadFavorites();
  loadSuggestions();
  const sessionId = route.query.session;
  const ticketId = route.query.ticket as string | undefined;
  if (sessionId) {
    const id = Number(sessionId);
    const session = sessions.value.find((s) => s.id === id);
    if (session) {
      await selectSession(session);
      router.replace({ path: route.path, query: ticketId ? { ticket: ticketId } : {} });
    }
  }
  if (ticketId && !sessionId) {
    await startNewChat({ ticketId });
    if (activeSession.value) {
      inputMessage.value = `[Ticket ${ticketId}]\n`;
    }
    router.replace({ path: route.path, query: {} });
  }
});

async function loadTicketLists() {
  ticketsLoading.value = true;
  try {
    const [deskRes, internalRes] = await Promise.all([
      listChatTickets("?page=1&limit=12"),
      listSupportTickets("?page=1&limit=12"),
    ]);
    desk365Tickets.value = deskRes.data ?? [];
    internalTickets.value = internalRes.data ?? [];
  } catch {
    desk365Tickets.value = [];
    internalTickets.value = [];
  } finally {
    ticketsLoading.value = false;
  }
}

async function loadSessions() {
  try {
    const res = await getMyChatSessions();
    sessions.value = res.data;
    connectionError.value = null;
  } catch (e) {
    if (isConnectionError(e)) connectionError.value = CONNECTION_MSG;
  }
}

async function loadFavorites() {
  try {
    const res = await listChatFavorites(`?limit=${SIDEBAR_PREVIEW_LIMIT + 1}&page=1`);
    favorites.value = res.data;
  } catch {
    // silent
  }
}

async function loadSuggestions() {
  try {
    const res = await getChatSuggestions();
    suggestions.value = res.data;
  } catch {
    // silent
  }
}

async function useDesk365TicketForQuestion(ticket: Desk365TicketChat) {
  if (!activeSession.value) {
    await startNewChat({ ticketId: ticket.ticketNumber });
  }

  const lines = [
    `Ticket Desk365 #${ticket.ticketNumber}`,
    `Subject: ${ticket.subject || "-"}`,
    `Status: ${ticket.statusLabel || "-"}`,
    "",
    "Please suggest troubleshooting steps and a response draft for this ticket.",
  ];
  inputMessage.value = lines.join("\n");
  await nextTick();
  textareaRef.value?.focus();
}

async function useInternalTicketForQuestion(ticket: SupportTicket) {
  await startNewChat();

  const lines = [
    `Internal Ticket #${ticket.ticketNumber}`,
    `Subject: ${ticket.subject || "-"}`,
    `Status: ${ticket.status || "-"}`,
    "",
    "Please suggest troubleshooting steps and a response draft for this internal ticket.",
  ];
  inputMessage.value = lines.join("\n");
  await nextTick();
  textareaRef.value?.focus();
}

function clearTicketContext() {
  currentTicketContext.value = null;
}

async function toggleFavorite(msg: ChatMessage) {
  try {
    await toggleChatMessageFavorite(msg.id);
    await loadFavorites();
    toast.success("Favorite updated");
  } catch {
    toast.error("Failed to update favorite");
  }
}

function setReplyTo(msg: ChatMessage | null) {
  replyToMsg.value = msg;
}

function clearReplyTo() {
  replyToMsg.value = null;
}

async function runSearch() {
  if (!activeSession.value || !searchQuery.value.trim()) return;
  try {
    const res = await searchChatMessages(activeSession.value.id, searchQuery.value.trim());
    searchResults.value = res.data;
  } catch {
    toast.error("Search failed");
  }
}

function clearSearch() {
  showSearch.value = false;
  searchQuery.value = "";
  searchResults.value = null;
}

async function toggleForwardDropdown(msg: ChatMessage) {
  if (forwardDropdownMsg.value?.id === msg.id) {
    closeForwardDropdown();
    return;
  }
  forwardDropdownMsg.value = msg;
  forwardSearch.value = "";
  try {
    forwardSessionList.value = sessions.value.filter((s) => s.id !== activeSession.value?.id);
  } catch {
    forwardSessionList.value = [];
  }
  nextTick(() => {
    forwardDropdownOutsideHandler = () => closeForwardDropdown();
    setTimeout(() => document.addEventListener("click", forwardDropdownOutsideHandler!), 0);
  });
}

let forwardDropdownOutsideHandler: (() => void) | null = null;

function closeForwardDropdown() {
  forwardDropdownMsg.value = null;
  forwardSearch.value = "";
  if (forwardDropdownOutsideHandler) {
    document.removeEventListener("click", forwardDropdownOutsideHandler);
    forwardDropdownOutsideHandler = null;
  }
}

async function doForwardToSession(msg: ChatMessage, session: ChatSession) {
  closeForwardDropdown();
  try {
    const payload = (msg.content || "").trim();
    if (!payload) {
      toast.error("Message is empty");
      return;
    }
    await sendChatMessage(session.id, payload);
    toast.success(`Forwarded to "${session.title || `Chat #${session.id}`}"`);
  } catch (e) {
    toast.error(handleConnectionError(e));
  }
}

function escapeHtml(input: string): string {
  return input
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function renderMessageHtml(input: string): string {
  const text = escapeHtml(input ?? "");
  const linked = text.replace(/(https?:\/\/[^\s<]+)/g, (raw) => {
    const cleaned = raw.replace(/[.,;:!?)]+$/, "");
    const tail = raw.slice(cleaned.length);
    return `<a href="${cleaned}" target="_blank" rel="noopener noreferrer" class="underline break-all">${cleaned}</a>${tail}`;
  });
  return linked.replace(/\n/g, "<br>");
}

function normalizeRawUrl(url: string): string {
  const clean = (url || "")
    .trim()
    .replaceAll("&amp;", "&")
    .replaceAll("&#41;", ")")
    .replaceAll("&#40;", "(");
  if (!clean) return "";
  if (/^(https?:\/\/|mailto:|tel:)/i.test(clean)) return clean;
  if (clean.startsWith("/")) return `${window.location.origin}${clean}`;
  if (/^index\.php/i.test(clean)) return `${window.location.origin}/${clean}`;
  if (/^[\w.-]+\.[a-z]{2,}/i.test(clean) || clean.startsWith("www.")) return `https://${clean}`;
  return clean;
}

function cleanExtractedUrl(raw: string): string {
  const trimmed = (raw || "").trim();
  if (!trimmed) return "";
  const left = trimmed
    .replace(/^\[+/, "")
    .replace(/^\(+/, "")
    .replace(/^<+/, "")
    .replace(/^\s+/, "");
  const right = left
    .replace(/\)+$/, "")
    .replace(/\]+$/, "")
    .replace(/>+$/, "")
    .replace(/[.,;:!?]+$/, "")
    .trim();
  return right.replaceAll("&amp;", "&");
}

function extractLinks(text: string): string[] {
  const markdownMatches = [...text.matchAll(/\[[^\]]+\]\(([^)]+)\)/g)].map((m) => m[1]);
  const inlineMatches = text.match(/((https?:\/\/|www\.)[^\s<>"'`]+)/gi) ?? [];
  const localMatches = text.match(/(index\.php\?[^\s<>"'`]+)/gi) ?? [];
  const candidates = [...markdownMatches, ...inlineMatches, ...localMatches];

  const dedup = new Map<string, string>();
  for (const raw of candidates) {
    const cleaned = cleanExtractedUrl(raw);
    if (!cleaned || cleaned.includes("](") || cleaned.includes("[") || cleaned.includes(")http")) {
      continue;
    }
    const normalized = normalizeRawUrl(cleaned);
    if (!normalized) continue;
    const key = normalized.toLowerCase();
    if (!dedup.has(key)) {
      dedup.set(key, cleaned);
    }
  }

  return [...dedup.values()];
}

async function copyLink(url: string) {
  await copyMessage(url);
}

function openLink(url: string) {
  window.open(normalizeRawUrl(url), "_blank", "noopener,noreferrer");
}

function handleAssistantContentClick(event: MouseEvent) {
  const target = event.target as HTMLElement | null;
  const anchor = target?.closest("a") as HTMLAnchorElement | null;
  if (!anchor) return;
  event.preventDefault();
  event.stopPropagation();
  const href = anchor.getAttribute("href") || anchor.href || "";
  if (!href) return;
  window.open(normalizeRawUrl(href), "_blank", "noopener,noreferrer");
}

async function applySuggestion(s: ChatSuggestion) {
  if (!activeSession.value) {
    await startNewChat();
  }
  inputMessage.value = s.label;
}

async function startNewChat(opts?: { ticketId?: string }) {
  if (chatMode.value === "group" && !opts?.ticketId) {
    openGroupInvite();
    return;
  }
  isLoadingSession.value = true;
  try {
    const moduleFilter = selectedModule.value === "All Modules" ? undefined : selectedModule.value;
    const res = await newChatSession({
      moduleFilter,
      sessionType: chatMode.value,
      desk365TicketId: opts?.ticketId,
      participantIds: chatMode.value === "group" ? invitedAgentIds.value : undefined,
      title: opts?.ticketId ? `Ticket ${opts.ticketId}` : undefined,
    });
    const created = res.data.session;
    let hydrated = created;
    try {
      const detail = await getChatSession(created.id);
      hydrated = detail.data;
    } catch {
      // Fallback to created payload if detail API temporarily fails.
    }
    activeSession.value = hydrated;
    messages.value = hydrated.messages ?? [];
    sessions.value = [hydrated, ...sessions.value.filter((s) => s.id !== hydrated.id)];
    connectionError.value = null;
  } catch (err: unknown) {
    toast.error(handleConnectionError(err));
  } finally {
    isLoadingSession.value = false;
  }
}

async function selectSession(session: ChatSession) {
  activeSession.value = session;
  clearSearch();
  currentTicketContext.value = null;
  try {
    const res = await getChatSession(session.id);
    activeSession.value = res.data;
    messages.value = res.data.messages ?? [];
  } catch {
    messages.value = session.messages ?? [];
  }
}

function addFiles(files: FileList | File[]) {
  const list = Array.isArray(files) ? files : Array.from(files);
  for (let f of list) {
    if (f.size > MAX_FILE_SIZE) {
      toast.error(`File ${f.name} exceeds 4MB`);
      continue;
    }
    // Pasted images often have name "image" (no extension) — ensure valid filename
    if (f.type.startsWith("image/") && !f.name.includes(".")) {
      const ext = f.type.split("/")[1] || "png";
      f = new File([f], `image.${ext}`, { type: f.type });
    }
    const ext = "." + (f.name.split(".").pop() || "").toLowerCase();
    const isImageByMime = f.type.startsWith("image/");
    if (!ACCEPTED_TYPES.includes(ext) && !isImageByMime) {
      toast.error(`Unsupported format: ${f.name}`);
      continue;
    }
    if (!attachedFiles.value.some((x) => x.name === f.name && x.size === f.size)) {
      attachedFiles.value.push(f);
    }
  }
}

function getFilePreviewUrl(f: File): string {
  const key = `${f.name}-${f.size}-${f.lastModified}`;
  if (!previewUrls.has(key)) {
    previewUrls.set(key, URL.createObjectURL(f));
  }
  return previewUrls.get(key)!;
}

function removeFile(index: number) {
  const f = attachedFiles.value[index];
  if (f?.type.startsWith("image/")) {
    const key = `${f.name}-${f.size}-${f.lastModified}`;
    const url = previewUrls.get(key);
    if (url) {
      URL.revokeObjectURL(url);
      previewUrls.delete(key);
    }
  }
  attachedFiles.value.splice(index, 1);
}

function handlePaste(e: ClipboardEvent) {
  const items = e.clipboardData?.items;
  if (!items) return;
  for (const item of items) {
    if (item.type.startsWith("image/")) {
      e.preventDefault();
      const file = item.getAsFile();
      if (file) addFiles([file]);
      break;
    }
  }
}

function handleDrop(e: DragEvent) {
  isDragging.value = false;
  e.preventDefault();
  const files = e.dataTransfer?.files;
  if (files?.length) addFiles(files);
}

function handleDragOver(e: DragEvent) {
  e.preventDefault();
  isDragging.value = true;
}

function handleDragLeave() {
  isDragging.value = false;
}

function triggerFileSelect() {
  fileInputRef.value?.click();
}

async function sendMessage() {
  const hasText = inputMessage.value.trim();
  const hasFiles = attachedFiles.value.length > 0;
  if ((!hasText && !hasFiles) || !activeSession.value || isSending.value) return;

  const userText = inputMessage.value.trim() || "(Attachments only)";
  // If previous search results are still active, switch back to full conversation view for new messages.
  searchResults.value = null;
  const filesToSend = [...attachedFiles.value];
  inputMessage.value = "";
  // Revoke preview URLs sebelum clear
  attachedFiles.value.forEach((f) => {
    if (f.type.startsWith("image/")) {
      const key = `${f.name}-${f.size}-${f.lastModified}`;
      const url = previewUrls.get(key);
      if (url) {
        URL.revokeObjectURL(url);
        previewUrls.delete(key);
      }
    }
  });
  attachedFiles.value = [];
  isSending.value = true;

  messages.value.push({
    id: Date.now(),
    chatSessionId: activeSession.value.id,
    role: "user",
    content: userText + (filesToSend.length ? ` [${filesToSend.length} attachment(s)]` : ""),
    citations: [],
    createdAt: new Date().toISOString(),
  });

  await scrollToBottom();

  try {
    const mentionId = parseMentionFromMessage(userText);
    const opt: { replyToMessageId?: number; mentionToUserId?: number } = {};
    if (replyToMsg.value) opt.replyToMessageId = replyToMsg.value.id;
    if (typeof mentionId === "number") opt.mentionToUserId = mentionId;
    const res = await sendChatMessage(
      activeSession.value.id,
      userText,
      filesToSend.length ? filesToSend : undefined,
      Object.keys(opt).length ? opt : undefined,
    );
    clearReplyTo();
    if (res.data.role !== "user") messages.value.push(res.data);
    await syncActiveSessionMessages();
    connectionError.value = null;
    await scrollToBottom();
  } catch (err: unknown) {
    toast.error(handleConnectionError(err));
    messages.value.pop();
  } finally {
    isSending.value = false;
    await scrollToBottom();
  }
}

let pollIntervalId: ReturnType<typeof setInterval> | null = null;
let echoChannelName: string | null = null;

const POLL_INTERVAL_MS = 15000;

function mergeMessages(existing: ChatMessage[], incoming: ChatMessage[]): ChatMessage[] {
  if (incoming.length === 0) return existing;
  const byId = new Map<number, ChatMessage>();
  for (const msg of existing) byId.set(msg.id, msg);
  for (const msg of incoming) byId.set(msg.id, msg);
  return Array.from(byId.values()).sort((a, b) => {
    const tA = new Date(a.createdAt || 0).getTime();
    const tB = new Date(b.createdAt || 0).getTime();
    if (tA !== tB) return tA - tB;
    return a.id - b.id;
  });
}

async function syncActiveSessionMessages() {
  if (!activeSession.value) return;
  const res = await getChatSession(activeSession.value.id);
  const incoming = res.data.messages ?? [];
  messages.value = mergeMessages(messages.value, incoming);
}

function startMessagePolling() {
  stopMessagePolling();
  pollIntervalId = setInterval(async () => {
    if (!activeSession.value || isSending.value) return;
    try {
      const res = await getChatSession(activeSession.value.id);
      const incoming = res.data.messages ?? [];
      const prevIds = new Set(messages.value.map((m) => m.id));
      const hasNew = incoming.some((m) => !prevIds.has(m.id));
      if (hasNew) {
        messages.value = mergeMessages(messages.value, incoming);
        await scrollToBottom();
      }
    } catch {
      // silent
    }
  }, POLL_INTERVAL_MS);
}

function stopMessagePolling() {
  if (pollIntervalId) {
    clearInterval(pollIntervalId);
    pollIntervalId = null;
  }
}

function subscribeEcho(sessionId: number) {
  const echo = getEcho();
  if (!echo) return;
  const ch = `chat.session.${sessionId}`;
  echoChannelName = ch;
  echo.private(ch).listen(".message.sent", (e: unknown) => {
    const msg = (e as { message?: ChatMessage })?.message;
    if (!msg || !msg.id) return;
    const exists = messages.value.some((m) => m.id === msg.id);
    if (!exists) {
      messages.value.push(msg);
      scrollToBottom();
    }
  });
}

function unsubscribeEcho() {
  if (echoChannelName) {
    const echo = getEcho();
    if (echo) echo.leave(echoChannelName);
    echoChannelName = null;
  }
}

watch(
  () => activeSession.value?.id,
  (id) => {
    unsubscribeEcho();
    if (id) {
      subscribeEcho(id);
      startMessagePolling();
    } else {
      stopMessagePolling();
    }
  },
);

onBeforeUnmount(() => {
  unsubscribeEcho();
  stopMessagePolling();
  disconnectEcho();
  isDragging.value = false;
  previewUrls.forEach((url) => URL.revokeObjectURL(url));
  previewUrls.clear();
});

async function handleDeleteSession(sessionId: number) {
  try {
    await deleteChatSession(sessionId);
    sessions.value = sessions.value.filter((s) => s.id !== sessionId);
    if (activeSession.value?.id === sessionId) {
      activeSession.value = null;
      messages.value = [];
    }
    toast.success("Session deleted");
  } catch {
    toast.error("Failed to delete session");
  }
}

async function toggleSessionFavorite(session: ChatSession, e?: Event) {
  e?.stopPropagation();
  try {
    const res = await toggleChatSessionFavorite(session.id);
    session.isFavorited = res.data.favorited;
    if (activeSession.value?.id === session.id) {
      activeSession.value = { ...activeSession.value, isFavorited: res.data.favorited };
    }
    toast.success(res.data.favorited ? "Ditambah ke favorit" : "Dibuang dari favorit");
  } catch {
    toast.error("Gagal kemas kini favorit");
  }
}

async function scrollToBottom() {
  await nextTick();
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
  }
}

function handleKeydown(e: KeyboardEvent) {
  if (mentionDropdownOpen.value) {
    const cand = mentionCandidates.value;
    if (e.key === "ArrowDown") {
      e.preventDefault();
      mentionSelectedIndex.value = (mentionSelectedIndex.value + 1) % cand.length;
      return;
    }
    if (e.key === "ArrowUp") {
      e.preventDefault();
      mentionSelectedIndex.value = (mentionSelectedIndex.value - 1 + cand.length) % cand.length;
      return;
    }
    if (e.key === "Enter") {
      e.preventDefault();
      const item = cand[mentionSelectedIndex.value];
      if (item) {
        insertMention(item);
      }
      return;
    }
    if (e.key === "Escape") {
      e.preventDefault();
      mentionDropdownOpen.value = false;
      return;
    }
  }
  if (e.key === "Enter" && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
}

function formatTime(dateStr: string) {
  return new Date(dateStr).toLocaleTimeString("ms-MY", { hour: "2-digit", minute: "2-digit" });
}

async function copyMessage(text: string) {
  const content = text ?? "";
  try {
    await navigator.clipboard.writeText(content);
    toast.success("Copied to clipboard");
  } catch {
    try {
      const textArea = document.createElement("textarea");
      textArea.value = content;
      textArea.setAttribute("readonly", "");
      textArea.style.position = "absolute";
      textArea.style.left = "-9999px";
      document.body.appendChild(textArea);
      textArea.select();
      const copied = document.execCommand("copy");
      document.body.removeChild(textArea);
      if (copied) {
        toast.success("Copied to clipboard");
        return;
      }
    } catch {
      // fallthrough
    }
    toast.error("Failed to copy");
  }
}

function copyAllChat() {
  const lines = messages.value.map((m) => {
    const role = m.role === "user" ? "You" : "AI";
    return `${role}: ${m.content}`;
  });
  copyMessage(lines.join("\n\n"));
}

const hasActiveChat = computed(() => activeSession.value !== null);

const sortedSessions = computed(() => {
  const fav = sessions.value.filter((s) => s.isFavorited);
  const rest = sessions.value.filter((s) => !s.isFavorited);
  return [...fav, ...rest];
});

const activeGroupSessions = computed(() => {
  const active = activeSession.value;
  if (!active || active.sessionType !== "group") return [];
  return [active];
});

const mentionCandidates = computed(() => {
  const q = mentionQuery.value.toLowerCase().trim();
  const list: { id: number | null; name: string }[] = [{ id: null, name: "AI" }];
  const participants = activeSession.value?.participants ?? [];
  const myId = auth.user?.id;
  for (const p of participants) {
    if (p.id === myId) continue;
    if (!q || p.name.toLowerCase().includes(q)) {
      list.push({ id: p.id, name: p.name });
    }
  }
  return list;
});

function onInputMessage(e: Event) {
  const el = e.target as HTMLTextAreaElement;
  inputMessage.value = el.value;
  const cursor = el.selectionStart ?? 0;
  const text = el.value;
  const beforeCursor = text.slice(0, cursor);
  const lastAt = beforeCursor.lastIndexOf("@");
  if (lastAt >= 0) {
    const afterAt = beforeCursor.slice(lastAt + 1);
    if (!afterAt.includes(" ") && !afterAt.includes("\n")) {
      mentionStartIndex.value = lastAt;
      mentionQuery.value = afterAt;
      mentionDropdownOpen.value = true;
      mentionSelectedIndex.value = 0;
      return;
    }
  }
  mentionDropdownOpen.value = false;
}

function insertMention(item: { id: number | null; name: string }) {
  const el = textareaRef.value;
  if (!el) return;
  const text = inputMessage.value;
  const start = mentionStartIndex.value;
  const end = start + 1 + mentionQuery.value.length;
  const before = text.slice(0, start);
  const after = text.slice(end);
  const replacement = `@${item.name} `;
  inputMessage.value = before + replacement + after;
  mentionDropdownOpen.value = false;
  nextTick(() => {
    el.focus();
    const pos = start + replacement.length;
    el.setSelectionRange(pos, pos);
  });
}

function parseMentionFromMessage(msg: string): number | null | undefined {
  const m = msg.match(/@(\S+)/);
  if (!m) return undefined;
  const name = m[1];
  if (/^AI$/i.test(name)) return null;
  const participants = activeSession.value?.participants ?? [];
  const found = participants.find((p) => p.name.toLowerCase() === name.toLowerCase());
  return found ? found.id : undefined;
}

// Paparan: Docs, Links, Media from current session messages
const paparanDocs = computed(() => {
  const items: string[] = [];
  for (const m of messages.value) {
    if (m.role === "assistant" && m.citations?.length) {
      for (const c of m.citations) {
        if (c && !items.includes(c)) items.push(c);
      }
    }
  }
  return items;
});

const paparanLinks = computed(() => {
  const items = new Set<string>();
  for (const m of messages.value) {
    const links = extractLinks(m.content || "");
    for (const link of links) {
      items.add(link);
    }
  }
  return [...items];
});

const paparanMedia = computed(() => {
  const items: string[] = [];
  for (const m of messages.value) {
    if (m.role === "user" && m.content?.includes("[") && (m.content?.includes("lampiran") || m.content?.includes("attachment"))) {
      const match = m.content.match(/\[(\d+)\s*(?:lampiran|attachment\(s\))\]/);
      if (match) items.push(`Attachment: ${match[1]} file(s)`);
    }
  }
  return [...new Set(items)];
});

function togglePaparan(key: keyof typeof paparanOpen.value) {
  paparanOpen.value[key] = !paparanOpen.value[key];
}

async function openGroupInvite() {
  showGroupInvite.value = true;
  invitedAgentIds.value = [];
  try {
    const res = await listAgentPicklist();
    availableAgents.value = (res.data ?? []).filter((u) => u.id !== auth.user?.id);
  } catch {
    availableAgents.value = [];
  }
}

function closeGroupInvite() {
  showGroupInvite.value = false;
  inviteAgentSearch.value = "";
}

async function addMembersToGroup() {
  if (!activeSession.value?.participantIds || activeSession.value.sessionType !== "group") return;
  showAddGroupMember.value = true;
  inviteAgentSearch.value = "";
  try {
    const res = await listAgentPicklist();
    availableAgents.value = (res.data ?? []).filter((u) => u.id !== auth.user?.id);
  } catch {
    availableAgents.value = [];
  }
}

function closeAddGroupMember() {
  showAddGroupMember.value = false;
}

const chatShareUrl = computed(() => {
  if (!activeSession.value?.id) return "";
  return `${window.location.origin}/admin/kerisi/chat?session=${activeSession.value.id}`;
});

const chatShareQrUrl = computed(() => {
  const url = chatShareUrl.value;
  if (!url) return "";
  return `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(url)}`;
});

async function copyChatShareLink() {
  const url = chatShareUrl.value;
  if (!url) return;
  try {
    await navigator.clipboard.writeText(url);
    toast.success("Link disalin");
  } catch {
    toast.error("Gagal salin link");
  }
}

async function confirmAddToGroup(userIds: number[]) {
  if (!activeSession.value) return;
  const current = activeSession.value.participantIds ?? [];
  const merged = [...new Set([...current, ...userIds])];
  try {
    const res = await updateChatSession(activeSession.value.id, { participantIds: merged });
    activeSession.value = res.data;
    showAddGroupMember.value = false;
    invitedAgentIds.value = [];
    toast.success("Members added");
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to add members");
  }
}

function toggleInviteAgent(u: AgentPicklistItem) {
  const i = invitedAgentIds.value.indexOf(u.id);
  if (i >= 0) invitedAgentIds.value = invitedAgentIds.value.filter((id) => id !== u.id);
  else invitedAgentIds.value = [...invitedAgentIds.value, u.id];
}

const filteredInviteAgents = computed(() => {
  const q = inviteAgentSearch.value.trim().toLowerCase();
  if (!q) return availableAgents.value;
  return availableAgents.value.filter(
    (u) =>
      (u.name ?? "").toLowerCase().includes(q) ||
      (u.email ?? "").toLowerCase().includes(q)
  );
});

const filteredForwardUsers = computed(() => {
  const q = forwardSearch.value.trim().toLowerCase();
  if (!q) return forwardSessionList.value;
  return forwardSessionList.value.filter(
    (s) =>
      (s.title ?? "").toLowerCase().includes(q) ||
      String(s.id).includes(q)
  );
});

function insertEmoji(emoji: string) {
  inputMessage.value = `${inputMessage.value}${emoji}`;
  showEmojiPicker.value = false;
}

async function createTicketFromMessage(msg: ChatMessage) {
  const content = msg.content?.trim();
  if (!content) {
    toast.error("Message is empty");
    return;
  }
  const excerpt = content.length > 100 ? `${content.slice(0, 100)}...` : content;
  const draft = {
    subject: `SELAR Chat: ${excerpt}`,
    body: content,
    source: "kerisi-chat",
  };
  localStorage.setItem("kerisi_ticket_draft_from_chat", JSON.stringify(draft));
  await router.push({
    path: "/admin/kerisi/ticket",
    query: {
      create: "1",
      fromChat: "1",
      source: "kerisi-chat",
      subject: draft.subject,
    },
  });
}

async function startGroupChatWithInvited() {
  const selectedIds = [...invitedAgentIds.value];
  isLoadingSession.value = true;
  try {
    const moduleFilter = selectedModule.value === "All Modules" ? undefined : selectedModule.value;
    const res = await newChatSession({
      moduleFilter,
      sessionType: "group",
      participantIds: invitedAgentIds.value,
      title: "Group Chat",
    });
    const created = res.data.session;
    let hydrated = created;
    try {
      const detail = await getChatSession(created.id);
      hydrated = detail.data;
    } catch {
      // Fallback to created payload if detail API temporarily fails.
    }
    activeSession.value = hydrated;
    messages.value = hydrated.messages ?? [];
    sessions.value = [hydrated, ...sessions.value.filter((s) => s.id !== hydrated.id)];
    showGroupInvite.value = false;
    const participantIds = new Set((hydrated.participants ?? []).map((p) => p.id));
    const missingSelected = selectedIds.filter((id) => !participantIds.has(id));
    invitedAgentIds.value = [];
    connectionError.value = null;
    if (missingSelected.length > 0) {
      const names = availableAgents.value
        .filter((a) => missingSelected.includes(a.id))
        .map((a) => a.name);
      toast.error(
        names.length > 0
          ? `Some selected agents were not added: ${names.join(", ")}`
          : "Some selected agents were not added (access/scope restriction).",
      );
    }
  } catch (err: unknown) {
    toast.error(handleConnectionError(err));
  } finally {
    isLoadingSession.value = false;
  }
}
</script>

<template>
  <AdminLayout>
    <div class="flex h-[calc(100vh-4rem)] overflow-y-hidden overflow-x-visible bg-gray-50 flex-col dark:bg-slate-950">
      <!-- Connection error banner -->
      <div
        v-if="connectionError"
        class="flex-shrink-0 flex items-center justify-between gap-4 px-4 py-3 bg-rose-50 border-b border-rose-200 text-rose-800 text-sm"
      >
        <span class="flex-1">{{ connectionError }}</span>
        <button
          type="button"
          @click="retryConnection"
          :disabled="isRetryingConnection"
          class="flex-shrink-0 px-4 py-2 bg-rose-600 hover:bg-rose-700 disabled:opacity-50 text-white rounded-lg font-medium transition-colors"
        >
          <span v-if="isRetryingConnection" class="inline-flex items-center gap-1"><Loader2 class="w-4 h-4 animate-spin" /> Mencuba...</span>
          <span v-else>Cuba semula</span>
        </button>
      </div>

      <div class="flex flex-1 overflow-y-hidden overflow-x-visible">
      <!-- Sidebar: Chat History -->
      <div class="w-72 flex-shrink-0 border-r border-gray-200 bg-white flex flex-col dark:border-slate-700 dark:bg-slate-900">
        <!-- Header -->
        <div class="border-b border-gray-200 p-4 dark:border-slate-700">
          <div class="mb-3 flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-sky-600 dark:bg-sky-500">
              <Bot class="h-5 w-5 text-white" />
            </div>
            <div>
              <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ BRANDING.SUPPORT_CHAT_NAME }}</h2>
              <p class="text-xs text-gray-500 dark:text-slate-400">{{ BRANDING.SUPPORT_CHAT_TAGLINE }}</p>
            </div>
          </div>

          <!-- Module filter -->
          <select
            v-model="selectedModule"
            class="mb-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-sky-500 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
          >
            <option v-for="mod in KERISI_MODULES" :key="mod" :value="mod">{{ mod }}</option>
          </select>

          <!-- Chat mode: Solo / Group -->
          <div class="mb-2 flex gap-1 rounded-lg bg-gray-100 p-1 dark:bg-slate-800">
            <button
              type="button"
              @click="chatMode = 'solo'"
              class="flex-1 rounded-md py-1.5 text-xs transition-colors"
              :class="chatMode === 'solo' ? 'bg-white text-sky-600 shadow dark:bg-slate-950 dark:text-sky-400' : 'text-gray-600 hover:text-gray-800 dark:text-slate-400 dark:hover:text-slate-200'"
            >
              New Chat
            </button>
            <button
              type="button"
              @click="chatMode = 'group'"
              class="flex-1 rounded-md py-1.5 text-xs transition-colors"
              :class="chatMode === 'group' ? 'bg-white text-sky-600 shadow dark:bg-slate-950 dark:text-sky-400' : 'text-gray-600 hover:text-gray-800 dark:text-slate-400 dark:hover:text-slate-200'"
            >
              Group
            </button>
          </div>

          <button
            @click="startNewChat()"
            :disabled="isLoadingSession"
            class="flex w-full items-center justify-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-sky-700 disabled:opacity-50 dark:bg-sky-600 dark:hover:bg-sky-500"
          >
            <Loader2 v-if="isLoadingSession" class="w-4 h-4 animate-spin" />
            <Plus v-else class="w-4 h-4" />
            {{ chatMode === "group" ? "New Group Chat" : "New Chat" }}
          </button>

          <!-- Group invite panel (shown when group mode + click Group Chat Baru) -->
          <div v-if="showGroupInvite" class="mt-2 p-2 border border-gray-200 rounded-lg bg-gray-50 space-y-2">
            <p class="text-xs font-medium text-gray-700 flex items-center gap-1">
              <UserPlus class="w-3.5 h-3.5" />
              Invite Agents
            </p>
            <input
              v-model="inviteAgentSearch"
              type="text"
              placeholder="Search by name or email..."
              class="w-full text-xs border border-gray-200 rounded-md px-2 py-1.5"
            />
            <div class="max-h-32 overflow-y-auto space-y-1">
              <button
                v-for="u in filteredInviteAgents"
                :key="u.id"
                @click="toggleInviteAgent(u)"
                class="w-full text-left text-xs px-2 py-1.5 rounded-md flex items-center gap-2 transition-colors"
                :class="invitedAgentIds.includes(u.id) ? 'bg-blue-100 text-blue-800' : 'hover:bg-gray-100 text-gray-700'"
              >
                <span class="w-4 h-4 rounded border flex items-center justify-center text-[10px] shrink-0">
                  {{ invitedAgentIds.includes(u.id) ? "✓" : "" }}
                </span>
                {{ u.name }}
              </button>
            </div>
            <p v-if="invitedAgentIds.length > 0" class="text-[10px] text-gray-500">
              {{ invitedAgentIds.length }} agent(s) selected. Use @name to tag in chat.
            </p>
            <div class="flex gap-1">
              <button
                @click="startGroupChatWithInvited()"
                :disabled="isLoadingSession || invitedAgentIds.length === 0"
                class="flex-1 text-xs py-1.5 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
              >
                Start Group Chat
              </button>
              <button
                @click="closeGroupInvite"
                class="text-xs py-1.5 px-2 border rounded-md hover:bg-gray-100"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>

        <!-- Sidebar sections -->
        <div class="flex-1 overflow-y-auto overflow-x-visible p-2 space-y-4">
          <!-- Desk365 Tickets -->
          <div>
            <p class="text-xs font-medium text-gray-500 mb-1 flex items-center gap-1">
              <Ticket class="w-3.5 h-3.5" />
              Desk365 Tickets
            </p>
            <p v-if="ticketsLoading" class="text-xs text-gray-400">Loading...</p>
            <p v-else-if="desk365Tickets.length === 0" class="text-xs text-gray-400">No Desk365 tickets</p>
            <div v-else class="space-y-1">
              <button
                v-for="ticket in desk365Tickets.slice(0, SIDEBAR_PREVIEW_LIMIT)"
                :key="ticket.ticketNumber"
                type="button"
                @click="useDesk365TicketForQuestion(ticket)"
                class="relative z-0 w-full text-left p-2 rounded-lg border border-gray-200 hover:bg-blue-50 hover:border-blue-200 transition-colors hover:z-50"
              >
                <div class="flex items-center justify-between gap-2">
                  <p class="text-[11px] font-medium text-gray-700">#{{ ticket.ticketNumber }}</p>
                  <span class="group/info relative inline-flex items-center">
                    <span
                      class="inline-flex h-4 w-4 items-center justify-center rounded-full border border-gray-300 text-[10px] font-semibold text-gray-500"
                    >
                      i
                    </span>
                    <span
                      class="pointer-events-none absolute right-0 top-full z-[120] mt-1 hidden w-64 rounded-md border border-gray-200 bg-white p-2 text-[10px] text-gray-600 shadow-2xl group-hover/info:block"
                    >
                      <span class="block font-semibold text-gray-700 mb-1">Ticket Detail</span>
                      <span class="block">No: #{{ ticket.ticketNumber }}</span>
                      <span class="block">Subject: {{ ticket.subject || "-" }}</span>
                      <span class="block">Status: {{ ticket.statusLabel || "-" }}</span>
                      <span class="block">Category: {{ ticket.category || "-" }}</span>
                      <span class="block">Priority: {{ ticket.priority || "-" }}</span>
                    </span>
                  </span>
                </div>
                <p class="text-xs text-gray-600 truncate">{{ ticket.subject || "-" }}</p>
              </button>
            </div>
          </div>

          <!-- Internal Tickets -->
          <div>
            <p class="text-xs font-medium text-gray-500 mb-1 flex items-center gap-1">
              <FileText class="w-3.5 h-3.5" />
              Internal Tickets
            </p>
            <p v-if="ticketsLoading" class="text-xs text-gray-400">Loading...</p>
            <p v-else-if="internalTickets.length === 0" class="text-xs text-gray-400">No internal tickets</p>
            <div v-else class="space-y-1">
              <div
                v-for="ticket in internalTickets.slice(0, SIDEBAR_PREVIEW_LIMIT)"
                :key="ticket.id"
                class="relative z-0 p-2 rounded-lg border border-gray-200 hover:z-50"
              >
                <button
                  type="button"
                  @click="useInternalTicketForQuestion(ticket)"
                  class="w-full text-left"
                >
                <div class="flex items-center justify-between gap-2 mb-1">
                  <div class="flex items-center gap-1">
                    <p class="text-[11px] font-medium text-gray-700">#{{ ticket.ticketNumber }}</p>
                    <span class="group/info relative inline-flex items-center">
                      <span
                        class="inline-flex h-4 w-4 items-center justify-center rounded-full border border-gray-300 text-[10px] font-semibold text-gray-500"
                      >
                        i
                      </span>
                      <span
                        class="pointer-events-none absolute left-1/2 top-full z-[120] mt-1 hidden w-64 -translate-x-1/2 rounded-md border border-gray-200 bg-white p-2 text-[10px] text-gray-600 shadow-2xl group-hover/info:block"
                      >
                        <span class="block font-semibold text-gray-700 mb-1">Ticket Detail</span>
                        <span class="block">No: #{{ ticket.ticketNumber }}</span>
                        <span class="block">Subject: {{ ticket.subject || "-" }}</span>
                        <span class="block">Status: {{ ticket.status || "-" }}</span>
                        <span class="block">Priority: {{ ticket.priority || "-" }}</span>
                        <span class="block">Module: {{ ticket.module || "-" }}</span>
                      </span>
                    </span>
                  </div>
                </div>
                <p class="text-xs text-gray-600 truncate">{{ ticket.subject || "-" }}</p>
                </button>
              </div>
            </div>
          </div>

          <!-- Favorites -->
          <div v-if="favorites.length > 0">
            <p class="text-xs font-medium text-gray-500 mb-1 flex items-center gap-1">
              <Star class="w-3.5 h-3.5" />
              Favorites
            </p>
            <div class="space-y-1">
              <div
                v-for="fav in favorites.slice(0, SIDEBAR_PREVIEW_LIMIT)"
                :key="fav.id"
                @click="fav.session ? selectSession(fav.session) : null"
                class="text-xs p-2 rounded-lg cursor-pointer hover:bg-gray-100 truncate"
              >
                {{ fav.message?.content?.slice(0, 40) || "..." }}...
              </div>
              <p v-if="favorites.length > SIDEBAR_PREVIEW_LIMIT" class="text-xs text-gray-400 pl-2">...</p>
            </div>
          </div>

          <!-- Chat History -->
          <div>
            <p class="text-xs font-medium text-gray-500 mb-1 flex items-center gap-1">
              <MessageSquare class="w-3.5 h-3.5" />
              Chat History
            </p>
            <p v-if="sessions.length === 0" class="text-xs text-gray-400">No sessions yet</p>
            <div class="space-y-1">
              <div
                v-for="session in sortedSessions.slice(0, chatHistoryVisibleCount)"
                :key="session.id"
                @click="selectSession(session)"
                class="group flex items-start gap-2 p-2 rounded-lg cursor-pointer transition-colors"
                :class="activeSession?.id === session.id ? 'bg-blue-50 text-blue-700' : 'hover:bg-gray-100 text-gray-700'"
              >
                <MessageSquare class="w-4 h-4 mt-0.5 flex-shrink-0" />
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium truncate flex items-center gap-1">
                    <Star v-if="session.isFavorited" class="w-3 h-3 fill-amber-500 text-amber-500 shrink-0" />
                    {{ session.title || "New Chat" }}
                  </p>
                  <p v-if="session.moduleFilter" class="text-xs opacity-60 truncate">{{ session.moduleFilter }}</p>
                </div>
                <button
                  @click.stop="toggleSessionFavorite(session)"
                  class="opacity-0 group-hover:opacity-100 p-1 transition-colors"
                  :class="session.isFavorited ? 'text-amber-500' : 'text-gray-400 hover:text-amber-500'"
                  :title="session.isFavorited ? 'Buang dari favorit' : 'Tambah ke favorit'"
                >
                  <Star class="w-3.5 h-3.5" :class="session.isFavorited ? 'fill-amber-500' : ''" />
                </button>
                <button
                  @click.stop="handleDeleteSession(session.id)"
                  class="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-500 p-1"
                >
                  <Trash2 class="w-3.5 h-3.5" />
                </button>
              </div>
              <button
                v-if="sortedSessions.length > chatHistoryVisibleCount"
                type="button"
                @click="chatHistoryVisibleCount = Math.min(chatHistoryVisibleCount + CHAT_HISTORY_LOAD_MORE, sortedSessions.length)"
                class="w-full text-left text-xs text-gray-500 hover:text-blue-600 pl-2 py-1.5 hover:bg-gray-50 rounded"
              >
                ... Muat 4 lagi
              </button>
            </div>
          </div>

          <!-- Active Group Chat -->
          <div v-if="activeGroupSessions.length > 0">
            <p class="text-xs font-medium text-gray-500 mb-1 flex items-center gap-1">
              <UserPlus class="w-3.5 h-3.5" />
              Active Group Chat
            </p>
            <div class="space-y-1">
              <div
                v-for="session in activeGroupSessions.slice(0, SIDEBAR_PREVIEW_LIMIT)"
                :key="session.id"
                @click="selectSession(session)"
                class="group flex items-start gap-2 p-2 rounded-lg cursor-pointer transition-colors"
                :class="activeSession?.id === session.id ? 'bg-blue-50 text-blue-700' : 'hover:bg-gray-100 text-gray-700'"
              >
                <UserPlus class="w-4 h-4 mt-0.5 flex-shrink-0" />
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium truncate">{{ session.title || "Group Chat" }}</p>
                  <p v-if="session.participants?.length" class="text-[10px] opacity-60 truncate">
                    {{ session.participants.map((p) => p.name).join(", ") }}
                  </p>
                </div>
                <button
                  @click.stop="toggleSessionFavorite(session)"
                  class="opacity-0 group-hover:opacity-100 p-1 transition-colors"
                  :class="session.isFavorited ? 'text-amber-500' : 'text-gray-400 hover:text-amber-500'"
                  :title="session.isFavorited ? 'Buang dari favorit' : 'Tambah ke favorit'"
                >
                  <Star class="w-3.5 h-3.5" :class="session.isFavorited ? 'fill-amber-500' : ''" />
                </button>
                <button
                  @click.stop="handleDeleteSession(session.id)"
                  class="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-500 p-1"
                >
                  <Trash2 class="w-3.5 h-3.5" />
                </button>
              </div>
              <p v-if="activeGroupSessions.length > SIDEBAR_PREVIEW_LIMIT" class="text-xs text-gray-400 pl-2">...</p>
            </div>
          </div>

          <!-- Suggestions -->
          <div v-if="suggestions.length > 0">
            <p class="text-xs font-medium text-gray-500 mb-1">Suggestions</p>
            <div class="space-y-1">
              <button
                v-for="s in suggestions"
                :key="s.id"
                @click="applySuggestion(s)"
                class="w-full text-left text-xs p-2 rounded-lg hover:bg-blue-50 hover:text-blue-700 transition-colors truncate"
              >
                {{ s.label }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Main Chat Area -->
      <div class="flex-1 flex flex-col">

        <!-- Empty state -->
        <div
          v-if="!hasActiveChat"
          class="flex flex-1 flex-col items-center justify-center p-8 text-center"
        >
          <div class="mb-4 flex h-20 w-20 items-center justify-center rounded-2xl bg-sky-100 dark:bg-sky-950/80">
            <Bot class="h-10 w-10 text-sky-600 dark:text-sky-400" />
          </div>
          <h3 class="mb-1 text-xl font-semibold text-gray-800 dark:text-slate-100">
            {{ BRANDING.SUPPORT_CHAT_NAME }}
          </h3>
          <p class="mb-2 max-w-md text-sm text-sky-700/90 dark:text-sky-300/90">
            {{ BRANDING.SUPPORT_CHAT_TAGLINE }}
          </p>
          <p class="mb-6 max-w-md text-gray-500 dark:text-slate-400">
            Ask anything about {{ BRANDING.ERP_SYSTEM_NAME }}. Answers use manuals, documentation, tickets, and live data
            where your role allows.
          </p>
          <div class="grid grid-cols-2 gap-3 max-w-lg w-full">
            <button
              v-for="example in ['How to create a GL journal?', 'How to process monthly payroll?', 'How to add a new vendor?', 'How to reconcile cashbook?']"
              :key="example"
              @click="() => startNewChat()"
              class="rounded-xl border border-gray-200 bg-white p-3 text-left text-sm text-gray-700 transition-colors hover:border-sky-300 hover:bg-sky-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:border-sky-600 dark:hover:bg-sky-950/50"
            >
              {{ example }}
            </button>
          </div>
          <button
            @click="() => startNewChat()"
            :disabled="isLoadingSession"
            class="mt-6 flex items-center gap-2 rounded-xl bg-sky-600 px-6 py-3 font-medium text-white transition-colors hover:bg-sky-700 disabled:opacity-50 dark:bg-sky-600 dark:hover:bg-sky-500"
          >
            <Loader2 v-if="isLoadingSession" class="w-4 h-4 animate-spin" />
            <Plus v-else class="w-4 h-4" />
            Start Chat
          </button>
        </div>

        <!-- Active chat -->
        <template v-else>
          <!-- Chat header -->
          <div
            class="flex items-center justify-between gap-3 border-b border-gray-200 bg-white px-6 py-3 dark:border-slate-700 dark:bg-slate-900"
          >
            <div class="flex items-center gap-3">
              <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-sky-600 dark:bg-sky-500">
                <Bot class="h-5 w-5 text-white" />
              </div>
              <div>
                <p class="text-sm font-medium text-gray-900 dark:text-slate-100">
                  {{ activeSession?.title || BRANDING.SUPPORT_CHAT_NAME }}
                </p>
                <p class="text-xs text-gray-500 dark:text-slate-400">{{ activeSession?.moduleFilter || "All Modules" }}</p>
              </div>
            </div>
            <div class="flex items-center gap-1">
              <button
                type="button"
                @click="showSearch = !showSearch"
                class="flex items-center gap-1.5 text-gray-500 hover:text-blue-600 hover:bg-blue-50 px-3 py-1.5 rounded-lg text-sm transition-colors"
                title="Search"
              >
                <Search class="w-4 h-4" />
              </button>
              <button
                type="button"
                @click="showSettings = !showSettings"
                class="flex items-center gap-1.5 text-gray-500 hover:text-blue-600 hover:bg-blue-50 px-3 py-1.5 rounded-lg text-sm transition-colors"
                title="Settings"
              >
                <Settings class="w-4 h-4" />
              </button>
              <button
                v-if="messages.length > 0"
                type="button"
                @click="copyAllChat"
                class="flex items-center gap-1.5 text-gray-500 hover:text-blue-600 hover:bg-blue-50 px-3 py-1.5 rounded-lg text-sm transition-colors"
                title="Copy all chat"
              >
                <Copy class="w-4 h-4" />
                Copy
              </button>
            </div>
          </div>

          <!-- Group members (below header when group session) -->
          <div
            v-if="activeSession?.sessionType === 'group'"
            class="bg-gray-50 border-b px-4 py-2 flex flex-wrap items-center gap-2"
          >
            <span class="text-xs font-medium text-gray-600">Members:</span>
            <template v-for="p in (activeSession.participants ?? [])" :key="p.id">
              <span class="text-xs px-2 py-1 bg-white border border-gray-200 rounded-md">{{ p.name }}</span>
            </template>
            <button
              @click="addMembersToGroup"
              class="text-xs px-2 py-1 border border-dashed border-gray-300 rounded-md hover:bg-gray-100 text-gray-600 flex items-center gap-1"
            >
              <UserPlus class="w-3 h-3" />
              Add
            </button>
            <button
              @click="showSharePanel = !showSharePanel"
              class="text-xs px-2 py-1 border border-dashed border-gray-300 rounded-md hover:bg-gray-100 text-gray-600 flex items-center gap-1"
            >
              <Link class="w-3 h-3" />
              {{ showSharePanel ? "Sembunyikan" : "Kongsi" }}
            </button>
            <!-- Share panel: link + QR -->
            <div v-if="showSharePanel" class="w-full mt-2 p-3 bg-white border rounded-lg flex flex-col sm:flex-row gap-4">
              <div class="flex-1">
                <p class="text-xs font-medium text-gray-600 mb-1">Link untuk agent/user buka chat ini:</p>
                <div class="flex gap-2">
                  <input
                    :value="chatShareUrl"
                    readonly
                    class="flex-1 text-xs border rounded px-2 py-1.5 bg-gray-50"
                  />
                  <button
                    @click="copyChatShareLink"
                    class="text-xs px-3 py-1.5 bg-blue-600 text-white rounded hover:bg-blue-700 flex items-center gap-1"
                  >
                    <Copy class="w-3 h-3" />
                    Salin
                  </button>
                </div>
              </div>
              <div class="flex flex-col items-center">
                <p class="text-xs font-medium text-gray-600 mb-1">QR Code</p>
                <img
                  v-if="chatShareQrUrl"
                  :src="chatShareQrUrl"
                  alt="QR Code"
                  class="w-[120px] h-[120px] border rounded"
                />
              </div>
            </div>
            <!-- Add member panel (inline) -->
            <div v-if="showAddGroupMember" class="w-full mt-2 p-2 bg-white border rounded-lg space-y-2">
              <input
                v-model="inviteAgentSearch"
                type="text"
                placeholder="Search by name or email..."
                class="w-full text-xs border rounded px-2 py-1.5"
              />
              <div class="max-h-24 overflow-y-auto space-y-1">
                <template v-for="u in filteredInviteAgents.filter((x) => !(activeSession?.participantIds ?? []).includes(x.id))" :key="u.id">
                  <button
                    @click="confirmAddToGroup([u.id])"
                    class="w-full text-left text-xs px-2 py-1.5 rounded hover:bg-gray-100"
                  >
                    {{ u.name }}
                  </button>
                </template>
                <p v-if="filteredInviteAgents.filter((x) => !(activeSession?.participantIds ?? []).includes(x.id)).length === 0" class="text-xs text-gray-400 py-2">
                  {{ inviteAgentSearch.trim() ? "No agents match your search." : "No agents to add. All agents may already be in the group." }}
                </p>
              </div>
              <button @click="closeAddGroupMember" class="text-xs text-gray-500 hover:text-gray-700">Cancel</button>
            </div>
          </div>

          <!-- Search bar -->
          <div v-if="showSearch" class="bg-gray-50 border-b px-4 py-2 flex gap-2">
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Search in chat..."
              class="flex-1 text-sm border rounded-lg px-3 py-2"
              @keydown.enter="runSearch"
            />
            <button
              @click="runSearch"
              class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700"
            >
              Search
            </button>
            <button @click="clearSearch" class="text-gray-500 hover:text-gray-700">Cancel</button>
          </div>

          <!-- Ticket context (when chat is from ticket) -->
          <div
            v-if="activeSession?.desk365TicketId && currentTicketContext"
            class="border-b border-amber-200 bg-amber-50 px-4 py-3"
          >
            <div class="flex items-start justify-between gap-2">
              <div class="flex-1 min-w-0">
                <p class="text-xs font-medium text-amber-800">Ticket #{{ currentTicketContext.ticketNumber }}</p>
                <p class="text-sm text-amber-900 mt-0.5">{{ currentTicketContext.subject }}</p>
                <p v-if="currentTicketContext.contactName || currentTicketContext.companyName" class="text-xs text-amber-700 mt-1">
                  <span v-if="currentTicketContext.contactName">Dibuat oleh: {{ currentTicketContext.contactName }}</span>
                  <span v-if="currentTicketContext.contactName && currentTicketContext.companyName"> | </span>
                  <span v-if="currentTicketContext.companyName">Customer: {{ currentTicketContext.companyName }}</span>
                </p>
                <p class="text-xs text-amber-700 mt-1 line-clamp-2">{{ currentTicketContext.description }}</p>
              </div>
              <button
                type="button"
                @click="clearTicketContext"
                class="text-amber-600 hover:text-amber-800 p-1"
                aria-label="Close context"
              >
                <X class="w-4 h-4" />
              </button>
            </div>
          </div>

          <!-- Settings panel: Paparan (collapsible Docs, Links, Media) -->
          <div v-if="showSettings" class="bg-gray-50 border-b px-4 py-3 text-sm">
            <p class="font-medium text-gray-700 mb-2">Display</p>
            <div class="space-y-1">
              <div class="border border-gray-200 rounded-lg overflow-hidden">
                <button
                  @click="togglePaparan('docs')"
                  class="w-full flex items-center justify-between px-3 py-2 text-left hover:bg-gray-100"
                >
                  <span class="flex items-center gap-2">
                    <FileText class="w-4 h-4 text-gray-500" />
                    Docs
                    <span class="text-xs text-gray-500">({{ paparanDocs.length }})</span>
                  </span>
                  <ChevronRight
                    class="w-4 h-4 text-gray-500 transition-transform"
                    :class="paparanOpen.docs && 'rotate-90'"
                  />
                </button>
                <div v-if="paparanOpen.docs" class="border-t border-gray-200 bg-white px-3 py-2 max-h-24 overflow-y-auto">
                  <p v-if="paparanDocs.length === 0" class="text-xs text-gray-400">No document references</p>
                  <p v-else v-for="(d, i) in paparanDocs" :key="i" class="text-xs text-gray-600 truncate py-0.5">{{ d }}</p>
                </div>
              </div>
              <div class="border border-gray-200 rounded-lg overflow-hidden">
                <button
                  @click="togglePaparan('links')"
                  class="w-full flex items-center justify-between px-3 py-2 text-left hover:bg-gray-100"
                >
                  <span class="flex items-center gap-2">
                    <Link class="w-4 h-4 text-gray-500" />
                    Links
                    <span class="text-xs text-gray-500">({{ paparanLinks.length }})</span>
                  </span>
                  <ChevronRight
                    class="w-4 h-4 text-gray-500 transition-transform"
                    :class="paparanOpen.links && 'rotate-90'"
                  />
                </button>
                <div v-if="paparanOpen.links" class="border-t border-gray-200 bg-white px-3 py-2 max-h-24 overflow-y-auto">
                  <p v-if="paparanLinks.length === 0" class="text-xs text-gray-400">No links</p>
                  <a v-else v-for="(url, i) in paparanLinks" :key="i" :href="url" target="_blank" rel="noopener" class="block text-xs text-blue-600 hover:underline truncate py-0.5">{{ url }}</a>
                </div>
              </div>
              <div class="border border-gray-200 rounded-lg overflow-hidden">
                <button
                  @click="togglePaparan('media')"
                  class="w-full flex items-center justify-between px-3 py-2 text-left hover:bg-gray-100"
                >
                  <span class="flex items-center gap-2">
                    <Image class="w-4 h-4 text-gray-500" />
                    Media
                    <span class="text-xs text-gray-500">({{ paparanMedia.length }})</span>
                  </span>
                  <ChevronRight
                    class="w-4 h-4 text-gray-500 transition-transform"
                    :class="paparanOpen.media && 'rotate-90'"
                  />
                </button>
                <div v-if="paparanOpen.media" class="border-t border-gray-200 bg-white px-3 py-2 max-h-24 overflow-y-auto">
                  <p v-if="paparanMedia.length === 0" class="text-xs text-gray-400">No media</p>
                  <p v-else v-for="(m, i) in paparanMedia" :key="i" class="text-xs text-gray-600 py-0.5">{{ m }}</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Messages -->
          <div ref="messagesContainer" class="flex-1 overflow-y-auto p-6 space-y-4">
            <div v-if="searchResults !== null" class="mb-2 text-xs text-gray-500">
              {{ searchResults.length }} search result(s)
            </div>
            <div v-else-if="messages.length === 0" class="text-center text-gray-400 text-sm mt-8">
              Type your question below to get started...
            </div>

            <div
              v-for="msg in (searchResults ?? messages)"
              :key="msg.id"
              class="flex gap-3"
              :class="msg.role === 'user' ? 'flex-row-reverse' : 'flex-row'"
            >
              <!-- Avatar -->
              <div
                class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center"
                :class="msg.role === 'user' ? 'bg-gray-200' : 'bg-blue-600'"
              >
                <User v-if="msg.role === 'user'" class="w-4 h-4 text-gray-600" />
                <Bot v-else class="w-4 h-4 text-white" />
              </div>

              <!-- Content: icons above, bubble below -->
              <div
                class="group/bubble flex flex-col gap-1 max-w-[70%]"
                :class="msg.role === 'user' ? 'items-end' : 'items-start'"
              >
                <!-- Icons row: outside and above bubble -->
                <div
                  class="flex gap-1 opacity-100 transition-opacity"
                  :class="msg.role === 'user' ? 'flex-row-reverse' : 'flex-row'"
                >
                  <button
                    type="button"
                    @click="setReplyTo(msg)"
                    class="p-1.5 rounded-lg transition-colors"
                    :class="msg.role === 'user' ? 'hover:bg-blue-100 text-blue-600' : 'hover:bg-gray-100 text-gray-500'"
                    title="Reply"
                  >
                    <Send class="w-3.5 h-3.5 rotate-180" />
                  </button>
                  <button
                    type="button"
                    @click="toggleFavorite(msg)"
                    class="p-1.5 rounded-lg transition-colors"
                    :class="msg.role === 'user' ? 'hover:bg-blue-100 text-blue-600' : 'hover:bg-gray-100 text-gray-500'"
                    title="Favorite"
                  >
                    <Star class="w-3.5 h-3.5" />
                  </button>
                  <div class="relative">
                    <button
                      type="button"
                      @click.stop="toggleForwardDropdown(msg)"
                      class="p-1.5 rounded-lg transition-colors"
                      :class="msg.role === 'user' ? 'hover:bg-blue-100 text-blue-600' : 'hover:bg-gray-100 text-gray-500'"
                      title="Forward kepada"
                    >
                      <Forward class="w-3.5 h-3.5" />
                    </button>
                    <!-- Forward user list dropdown -->
                    <div
                      v-if="forwardDropdownMsg?.id === msg.id"
                      @click.stop
                      class="absolute z-50 mt-1 min-w-[220px] max-h-64 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg flex flex-col"
                      :class="msg.role === 'user' ? 'right-0' : 'left-0'"
                    >
                      <p class="px-3 pt-2 pb-1 text-xs font-medium text-gray-500">Forward kepada:</p>
                      <input
                        v-model="forwardSearch"
                        type="text"
                        placeholder="Search chat..."
                        class="mx-2 mb-2 px-2 py-1.5 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                      />
                      <div class="overflow-y-auto max-h-40 py-1">
                        <button
                          v-for="u in filteredForwardUsers"
                          :key="u.id"
                          type="button"
                          @click="doForwardToSession(msg, u)"
                          class="w-full px-3 py-2 text-left text-sm hover:bg-gray-50 flex flex-col"
                        >
                          <span class="font-medium truncate">{{ u.title || `Chat #${u.id}` }}</span>
                          <span class="text-xs text-gray-400 truncate">Session #{{ u.id }}</span>
                        </button>
                        <p v-if="filteredForwardUsers.length === 0" class="px-3 py-2 text-xs text-gray-400">
                          {{ forwardSearch.trim() ? "No match" : "No chats" }}
                        </p>
                      </div>
                    </div>
                  </div>
                  <button
                    type="button"
                    @click="createTicketFromMessage(msg)"
                    class="p-1.5 rounded-lg transition-colors"
                    :class="msg.role === 'user' ? 'hover:bg-blue-100 text-blue-600' : 'hover:bg-gray-100 text-gray-500'"
                    title="Create ticket"
                  >
                    <Ticket class="w-3.5 h-3.5" />
                  </button>
                  <button
                    type="button"
                    @click="copyMessage(msg.content)"
                    class="p-1.5 rounded-lg transition-colors"
                    :class="msg.role === 'user' ? 'hover:bg-blue-100 text-blue-600' : 'hover:bg-gray-100 text-gray-500'"
                    title="Copy"
                  >
                    <Copy class="w-3.5 h-3.5" />
                  </button>
                </div>

                <!-- Bubble -->
                <div
                  class="rounded-2xl pl-4 pr-4 py-3 text-sm leading-relaxed"
                  :class="msg.role === 'user'
                    ? 'bg-blue-600 text-white rounded-tr-sm'
                    : 'bg-white text-gray-800 shadow-sm border border-gray-100 rounded-tl-sm'"
                >
                  <p v-if="msg.replyToMessage" class="text-xs opacity-80 mb-1 border-l-2 pl-2">
                    Reply: {{ msg.replyToMessage.content?.slice(0, 50) }}...
                  </p>
                  <div
                    v-if="msg.role === 'assistant'"
                    class="chat-markdown prose prose-sm max-w-none"
                    @click="handleAssistantContentClick"
                    v-html="markdownToSafeHtml(msg.content || '')"
                  />
                  <div v-else class="chat-markdown prose prose-sm max-w-none" v-html="renderMessageHtml(msg.content || '')" />
                  <p class="text-xs mt-1 opacity-60">{{ formatTime(msg.createdAt) }}</p>
                </div>
                <div v-if="extractLinks(msg.content || '').length" class="w-full space-y-1">
                  <div
                    v-for="url in extractLinks(msg.content || '')"
                    :key="`${msg.id}-${url}`"
                    class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs"
                  >
                    <Link class="h-3.5 w-3.5 text-gray-400" />
                    <p class="min-w-0 flex-1 truncate text-gray-600">{{ url }}</p>
                    <button type="button" class="rounded p-1 hover:bg-gray-100" title="Copy link" @click="copyLink(url)">
                      <Copy class="h-3.5 w-3.5 text-gray-500" />
                    </button>
                    <button type="button" class="rounded p-1 hover:bg-gray-100" title="Open link" @click="openLink(url)">
                      <ExternalLink class="h-3.5 w-3.5 text-gray-500" />
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Typing indicator -->
            <div v-if="isSending" class="flex gap-3">
              <div class="w-8 h-8 rounded-full bg-blue-600 flex-shrink-0 flex items-center justify-center">
                <Bot class="w-4 h-4 text-white" />
              </div>
              <div class="bg-white rounded-2xl rounded-tl-sm px-4 py-3 shadow-sm border border-gray-100">
                <div class="flex gap-1 items-center h-5">
                  <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms"></div>
                  <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms"></div>
                  <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms"></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Input area -->
          <div
            class="bg-white border-t border-gray-200 p-4"
            :class="isDragging ? 'ring-2 ring-blue-400 ring-inset' : ''"
            @dragover="handleDragOver"
            @dragleave="handleDragLeave"
            @drop="handleDrop"
          >
            <p v-if="activeSession?.sessionType === 'group'" class="text-xs text-gray-500 mb-2">
              Group chat: Ask AI as usual, or type <code class="bg-gray-100 px-1 rounded">@name</code> to tag an agent.
            </p>
            <!-- Attached files -->
            <div v-if="attachedFiles.length > 0" class="flex flex-wrap gap-2 mb-3 max-w-4xl mx-auto">
              <div
                v-for="(f, i) in attachedFiles"
                :key="`${f.name}-${f.size}`"
                class="flex items-center gap-2 bg-gray-100 rounded-lg overflow-hidden"
              >
                <!-- Thumbnail untuk imej -->
                <img
                  v-if="f.type.startsWith('image/')"
                  :src="getFilePreviewUrl(f)"
                  :alt="f.name"
                  class="w-12 h-12 object-cover flex-shrink-0"
                />
                <!-- Icon untuk fail bukan imej -->
                <div
                  v-else
                  class="w-12 h-12 bg-gray-200 flex items-center justify-center flex-shrink-0"
                >
                  <Paperclip class="w-5 h-5 text-gray-500" />
                </div>
                <span class="truncate max-w-[100px] text-sm py-2">{{ f.name }}</span>
                <button
                  type="button"
                  @click="removeFile(i)"
                  class="text-gray-500 hover:text-red-600 p-2 self-center"
                  aria-label="Remove attachment"
                >
                  <X class="w-4 h-4" />
                </button>
              </div>
            </div>
            <!-- Reply-to hint -->
            <div
              v-if="replyToMsg"
              class="flex items-center justify-between gap-2 mb-2 px-4 py-2 bg-blue-50 rounded-lg max-w-4xl mx-auto"
            >
              <p class="text-xs text-blue-700 truncate">
                Reply: {{ replyToMsg.content?.slice(0, 60) }}{{ (replyToMsg.content?.length ?? 0) > 60 ? "..." : "" }}
              </p>
              <button @click="clearReplyTo" class="text-blue-600 hover:text-blue-800 p-1">
                <X class="w-4 h-4" />
              </button>
            </div>
            <div class="flex items-end gap-3 max-w-4xl mx-auto">
              <input
                ref="fileInputRef"
                type="file"
                :accept="ACCEPTED_TYPES"
                multiple
                class="hidden"
                @change="(e) => {
                  const el = e.target as HTMLInputElement;
                  if (el.files?.length) { addFiles(el.files); el.value = ''; }
                }"
              />
              <button
                type="button"
                @click="triggerFileSelect"
                class="flex-shrink-0 w-12 h-12 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-xl flex items-center justify-center transition-colors"
                title="Attach file (PDF, DOCX, Excel, image)"
              >
                <Paperclip class="w-5 h-5" />
              </button>
              <div class="relative">
                <button
                  type="button"
                  @click="showEmojiPicker = !showEmojiPicker"
                  class="flex-shrink-0 w-12 h-12 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-xl flex items-center justify-center transition-colors"
                  title="Emoji"
                >
                  <Smile class="w-5 h-5" />
                </button>
                <div
                  v-if="showEmojiPicker"
                  class="absolute bottom-full mb-2 left-0 z-40 rounded-lg border border-gray-200 bg-white shadow p-2 flex gap-1"
                >
                  <button
                    v-for="emoji in quickEmojis"
                    :key="emoji"
                    type="button"
                    @click="insertEmoji(emoji)"
                    class="h-8 w-8 rounded hover:bg-gray-100"
                  >
                    {{ emoji }}
                  </button>
                </div>
              </div>
              <div class="flex-1 relative">
                <textarea
                  ref="textareaRef"
                  :value="inputMessage"
                  @input="(e) => { inputMessage = (e.target as HTMLTextAreaElement).value; onInputMessage(e); }"
                  @keydown="handleKeydown"
                  @paste="handlePaste"
                  placeholder="Type your question 🤖 or paste image... (Enter to send, Shift+Enter for new line). Use @ to mention group members or AI."
                  rows="1"
                  class="w-full resize-none border border-gray-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent max-h-32"
                  style="min-height: 48px"
                />
                <div
                  v-if="mentionDropdownOpen && mentionCandidates.length"
                  class="absolute bottom-full left-0 mb-1 w-64 max-h-48 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-lg z-50 py-1"
                >
                  <button
                    v-for="(item, i) in mentionCandidates"
                    :key="item.id ?? 'ai'"
                    type="button"
                    :class="[
                      'w-full text-left px-3 py-2 text-sm flex items-center gap-2',
                      i === mentionSelectedIndex ? 'bg-blue-50 text-blue-700' : 'hover:bg-gray-50',
                    ]"
                    @click="insertMention(item)"
                  >
                    <Bot v-if="item.id === null" class="w-4 h-4 text-blue-500" />
                    <User v-else class="w-4 h-4 text-gray-500" />
                    {{ item.name }}
                  </button>
                </div>
              </div>
              <button
                @click="sendMessage"
                :disabled="(!inputMessage.trim() && !attachedFiles.length) || isSending"
                class="flex-shrink-0 w-12 h-12 bg-blue-600 hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-xl flex items-center justify-center transition-colors"
              >
                <Loader2 v-if="isSending" class="w-5 h-5 animate-spin" />
                <Send v-else class="w-5 h-5" />
              </button>
            </div>
            <p class="text-xs text-gray-400 text-center mt-2">
              PDF, DOCX, Excel, PNG, JPG — drag or paste image. AI will understand content for technical support.
            </p>
          </div>
        </template>
      </div>
      </div>
    </div>

  </AdminLayout>
</template>

<style scoped>
.chat-markdown :deep(a) {
  @apply text-blue-600 underline hover:text-blue-700;
}
.chat-markdown :deep(h1),
.chat-markdown :deep(h2),
.chat-markdown :deep(h3) {
  @apply font-semibold mt-2 mb-1;
}
.chat-markdown :deep(ul) {
  @apply list-disc pl-4 my-2;
}
.chat-markdown :deep(ol) {
  @apply list-decimal pl-4 my-2;
}
.chat-markdown :deep(code) {
  @apply bg-gray-100 px-1 rounded text-xs;
}
.chat-markdown :deep(pre) {
  @apply bg-gray-100 rounded p-2 overflow-x-auto text-xs my-2;
}
</style>
