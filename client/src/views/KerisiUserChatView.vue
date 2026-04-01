<script setup lang="ts">
import { ref, nextTick, onMounted, computed, onBeforeUnmount, watch } from "vue";
import AdminLayout from "@/layouts/AdminLayout.vue";
import { useToast } from "@/composables/useToast";
import {
  newUserChatSession,
  sendUserChatMessage,
  getUserChatSession,
  getMyUserChatSessions,
  deleteUserChatSession,
  toggleUserChatSessionFavorite,
  listUserChatFavorites,
  toggleUserChatMessageFavorite,
  searchUserChatMessages,
  getUserChatSuggestions,
} from "@/api/cms";
import { ensureCsrfCookie } from "@/api/client";
import { markdownToSafeHtml } from "@/utils/markdown";
import { getEcho, disconnectEcho } from "@/api/echo";
import { useRoute, useRouter } from "vue-router";
import type { ChatSession, ChatMessage, ChatSuggestion } from "@/types";
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
  Reply,
  Forward,
  Ticket,
  Smile,
  Link as LinkIcon,
  ExternalLink,
} from "lucide-vue-next";

const toast = useToast();
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

const favorites = ref<{ id: number; message: ChatMessage; session: ChatSession | null }[]>([]);
const suggestions = ref<ChatSuggestion[]>([]);
const searchQuery = ref("");
const showSearch = ref(false);
const searchResults = ref<ChatMessage[] | null>(null);
const connectionError = ref<string | null>(null);
const isRetryingConnection = ref(false);
const replyToMessage = ref<ChatMessage | null>(null);
const showEmojiPicker = ref(false);
const forwardingMessageId = ref<number | null>(null);
const forwardTargetSessionId = ref<number | null>(null);

const ACCEPTED_TYPES = ".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp,.gif";
const MAX_FILE_SIZE = 4 * 1024 * 1024;
const QUICK_EMOJIS = ["😀", "👍", "🙏", "✅", "✨", "🎯", "📌", "🚀"];

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
    const res = await getMyUserChatSessions();
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
  loadFavorites();
  loadSuggestions();
  const sessionId = route.query.session;
  if (sessionId) {
    const id = Number(sessionId);
    const session = sessions.value.find((s) => s.id === id);
    if (session) {
      await selectSession(session);
      router.replace({ path: route.path, query: {} });
    }
  }
});

async function loadSessions() {
  try {
    const res = await getMyUserChatSessions();
    sessions.value = res.data;
    connectionError.value = null;
  } catch (e) {
    if (isConnectionError(e)) connectionError.value = CONNECTION_MSG;
  }
}

async function loadFavorites() {
  try {
    const res = await listUserChatFavorites(`?limit=${SIDEBAR_PREVIEW_LIMIT + 1}&page=1`);
    favorites.value = res.data;
  } catch {
    // silent
  }
}

async function loadSuggestions() {
  try {
    const res = await getUserChatSuggestions();
    suggestions.value = res.data;
  } catch {
    // silent
  }
}

async function toggleFavorite(msg: ChatMessage) {
  try {
    await toggleUserChatMessageFavorite(msg.id);
    await loadFavorites();
    toast.success("Favorite updated");
  } catch {
    toast.error("Failed to update favorite");
  }
}

async function runSearch() {
  if (!activeSession.value || !searchQuery.value.trim()) return;
  try {
    const res = await searchUserChatMessages(activeSession.value.id, searchQuery.value.trim());
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

async function applySuggestion(s: ChatSuggestion) {
  if (!activeSession.value) await startNewChat();
  inputMessage.value = s.label;
}

async function startNewChat() {
  isLoadingSession.value = true;
  try {
    const moduleFilter = selectedModule.value === "All Modules" ? undefined : selectedModule.value;
    const res = await newUserChatSession({ moduleFilter });
    activeSession.value = res.data.session;
    messages.value = [];
    sessions.value.unshift(res.data.session);
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
  try {
    const res = await getUserChatSession(session.id);
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
  if (!previewUrls.has(key)) previewUrls.set(key, URL.createObjectURL(f));
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
  const replyPrefix = replyToMessage.value?.content?.trim()
    ? `Reply to: "${replyToMessage.value.content.trim().slice(0, 120)}"\n\n`
    : "";
  const payloadText = replyPrefix + userText;
  const filesToSend = [...attachedFiles.value];
  inputMessage.value = "";
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
    content: payloadText + (filesToSend.length ? ` [${filesToSend.length} attachment(s)]` : ""),
    citations: [],
    createdAt: new Date().toISOString(),
  });

  await scrollToBottom();

  try {
    const res = await sendUserChatMessage(
      activeSession.value.id,
      payloadText,
      filesToSend.length ? filesToSend : undefined,
    );
    if (res.data.role !== "user") messages.value.push(res.data);
    connectionError.value = null;
    await scrollToBottom();
  } catch (err: unknown) {
    toast.error(handleConnectionError(err));
    messages.value.pop();
  } finally {
    isSending.value = false;
    replyToMessage.value = null;
    await scrollToBottom();
  }
}

let pollIntervalId: ReturnType<typeof setInterval> | null = null;
let echoChannelName: string | null = null;
const POLL_INTERVAL_MS = 15000;

function startMessagePolling() {
  stopMessagePolling();
  pollIntervalId = setInterval(async () => {
    if (!activeSession.value || isSending.value) return;
    try {
      const res = await getUserChatSession(activeSession.value.id);
      const incoming = res.data.messages ?? [];
      const prevIds = new Set(messages.value.map((m) => m.id));
      const hasNew = incoming.some((m) => !prevIds.has(m.id));
      if (hasNew) {
        messages.value = incoming;
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
    await deleteUserChatSession(sessionId);
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
    const res = await toggleUserChatSessionFavorite(session.id);
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
  if (e.key === "Enter" && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
}

function insertEmoji(emoji: string) {
  inputMessage.value += emoji;
  showEmojiPicker.value = false;
}

function setReplyMessage(msg: ChatMessage) {
  replyToMessage.value = msg;
}

function clearReplyMessage() {
  replyToMessage.value = null;
}

async function forwardMessage(msg: ChatMessage) {
  const targetId = Number(forwardTargetSessionId.value);
  if (!targetId) {
    toast.error("Select a target chat first");
    return;
  }
  try {
    await sendUserChatMessage(targetId, msg.content || "");
    toast.success("Message forwarded");
    forwardingMessageId.value = null;
    forwardTargetSessionId.value = null;
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Forward failed");
  }
}

function openForwardPicker(msg: ChatMessage) {
  forwardingMessageId.value = msg.id;
  forwardTargetSessionId.value = null;
}

function openCreateTicketFromMessage(msg: ChatMessage) {
  const content = msg.content?.trim() || "";
  if (!content) {
    toast.error("Message is empty");
    return;
  }
  const subjectPreview = content.length > 80 ? `${content.slice(0, 80)}...` : content;
  const draft = {
    subject: `AINA Chat: ${subjectPreview}`,
    body: content,
    source: "kerisi-user-chat",
  };
  localStorage.setItem("kerisi_ticket_draft_from_chat", JSON.stringify(draft));
  router.push({
    path: "/admin/kerisi/ticket",
    query: {
      create: "1",
      fromChat: "1",
      source: "kerisi-user-chat",
      subject: draft.subject,
    },
  });
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
  const clean = (url || "").trim().replaceAll("&amp;", "&");
  if (!clean) return "";
  if (/^(https?:\/\/|mailto:|tel:)/i.test(clean)) return clean;
  if (clean.startsWith("/")) return `${window.location.origin}${clean}`;
  if (/^index\.php/i.test(clean)) return `${window.location.origin}/${clean}`;
  if (/^[\w.-]+\.[a-z]{2,}/i.test(clean) || clean.startsWith("www.")) return `https://${clean}`;
  return clean;
}

function extractLinks(text: string): string[] {
  const inlineMatches = text.match(/((https?:\/\/|www\.)[^\s)]+)/gi) ?? [];
  const localMatches = text.match(/(index\.php\?[^\s)]+)/gi) ?? [];
  const markdownMatches = [...text.matchAll(/\[[^\]]+\]\(([^)]+)\)/g)].map((m) => m[1]);
  const combined = [...inlineMatches, ...localMatches, ...markdownMatches]
    .map((url) => url.replace(/[.,;:!?)]+$/, "").replaceAll("&amp;", "&"))
    .filter((url) => url.length > 0);
  return [...new Set(combined)];
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

/** Popup/embed from MYFIS2 or external app: /admin/kerisi/user-chat?embed=1 */
const isEmbed = computed(() => route.query.embed === "1");
</script>

<template>
  <AdminLayout :embed-mode="isEmbed">
    <div
      class="flex overflow-hidden bg-gray-50 flex-col dark:bg-slate-950"
      :class="isEmbed ? 'h-full min-h-0' : 'h-[calc(100vh-4rem)]'"
    >
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

      <div class="flex flex-1 overflow-hidden">
        <!-- Sidebar -->
        <div class="flex w-72 flex-shrink-0 flex-col border-r border-gray-200 bg-white dark:border-slate-700 dark:bg-slate-900">
          <div class="border-b border-gray-200 p-4 dark:border-slate-700">
            <div class="mb-3 flex items-center gap-2">
              <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-600 dark:bg-emerald-500">
                <Bot class="h-5 w-5 text-white" />
              </div>
              <div>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ BRANDING.USER_CHAT_NAME }}</h2>
                <p class="text-xs text-gray-500 dark:text-slate-400">{{ BRANDING.USER_CHAT_TAGLINE }}</p>
              </div>
            </div>

            <select
              v-model="selectedModule"
              class="mb-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-emerald-500 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
            >
              <option v-for="mod in KERISI_MODULES" :key="mod" :value="mod">{{ mod }}</option>
            </select>

            <button
              @click="startNewChat()"
              :disabled="isLoadingSession"
              class="w-full flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors"
            >
              <Loader2 v-if="isLoadingSession" class="w-4 h-4 animate-spin" />
              <Plus v-else class="w-4 h-4" />
              New Chat
            </button>
          </div>

          <div class="flex-1 overflow-y-auto p-2 space-y-4">
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
                  :class="activeSession?.id === session.id ? 'bg-emerald-50 text-emerald-700' : 'hover:bg-gray-100 text-gray-700'"
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
                  class="w-full text-left text-xs text-gray-500 hover:text-emerald-600 pl-2 py-1.5 hover:bg-gray-50 rounded"
                >
                  ... Muat 4 lagi
                </button>
              </div>
            </div>

            <div v-if="suggestions.length > 0">
              <p class="text-xs font-medium text-gray-500 mb-1">Suggestions</p>
              <div class="space-y-1">
                <button
                  v-for="s in suggestions"
                  :key="s.id"
                  @click="applySuggestion(s)"
                  class="w-full text-left text-xs p-2 rounded-lg hover:bg-emerald-50 hover:text-emerald-700 transition-colors truncate"
                >
                  {{ s.label }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Main Chat Area -->
        <div class="flex-1 flex flex-col">
          <div v-if="!hasActiveChat" class="flex-1 flex flex-col items-center justify-center text-center p-8">
            <div class="w-20 h-20 bg-emerald-100 rounded-2xl flex items-center justify-center mb-4">
              <Bot class="w-10 h-10 text-emerald-600" />
            </div>
            <h3 class="text-xl font-semibold text-gray-800 dark:text-slate-100 mb-2">{{ BRANDING.USER_CHAT_NAME }} — User Chat</h3>
            <p class="text-gray-500 max-w-md mb-6">
              Ask about procedures and how-to questions. Answers are based on the Knowledge Base (User Manuals, guides). For live data or SQL, contact your support team.
            </p>
            <div class="grid grid-cols-2 gap-3 max-w-lg w-full">
              <button
                v-for="example in ['How to create a GL journal?', 'How to process monthly payroll?', 'How to add a new vendor?', 'How to reconcile cashbook?']"
                :key="example"
                @click="startNewChat()"
                class="rounded-xl border border-gray-200 bg-white p-3 text-left text-sm text-gray-700 transition-colors hover:border-emerald-300 hover:bg-emerald-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:border-emerald-600 dark:hover:bg-emerald-950/40"
              >
                {{ example }}
              </button>
            </div>
            <button
              @click="startNewChat()"
              :disabled="isLoadingSession"
              class="mt-6 flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white px-6 py-3 rounded-xl font-medium transition-colors"
            >
              <Loader2 v-if="isLoadingSession" class="w-4 h-4 animate-spin" />
              <Plus v-else class="w-4 h-4" />
              Start Chat
            </button>
          </div>

          <template v-else>
            <div
              class="flex items-center justify-between gap-3 border-b border-gray-200 bg-white px-6 py-3 dark:border-slate-700 dark:bg-slate-900"
            >
              <div class="flex items-center gap-3">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-600 dark:bg-emerald-500">
                  <Bot class="h-5 w-5 text-white" />
                </div>
                <div>
                  <p class="text-sm font-medium text-gray-900 dark:text-slate-100">
                    {{ activeSession?.title || BRANDING.USER_CHAT_NAME }}
                  </p>
                  <p class="text-xs text-gray-500 dark:text-slate-400">{{ activeSession?.moduleFilter || "All Modules" }}</p>
                </div>
              </div>
              <div class="flex items-center gap-1">
                <button
                  type="button"
                  @click="showSearch = !showSearch"
                  class="flex items-center gap-1.5 text-gray-500 hover:text-emerald-600 hover:bg-emerald-50 px-3 py-1.5 rounded-lg text-sm transition-colors"
                  title="Search"
                >
                  <Search class="w-4 h-4" />
                </button>
                <button
                  v-if="messages.length > 0"
                  type="button"
                  @click="copyAllChat"
                  class="flex items-center gap-1.5 text-gray-500 hover:text-emerald-600 hover:bg-emerald-50 px-3 py-1.5 rounded-lg text-sm transition-colors"
                  title="Copy all chat"
                >
                  <Copy class="w-4 h-4" />
                  Copy
                </button>
              </div>
            </div>

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
                class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700"
              >
                Search
              </button>
              <button @click="clearSearch" class="text-gray-500 hover:text-gray-700">Cancel</button>
            </div>

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
                <div
                  class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center"
                  :class="msg.role === 'user' ? 'bg-gray-200' : 'bg-emerald-600'"
                >
                  <User v-if="msg.role === 'user'" class="w-4 h-4 text-gray-600" />
                  <Bot v-else class="w-4 h-4 text-white" />
                </div>

                <div
                  class="group/bubble flex flex-col gap-1 max-w-[70%]"
                  :class="msg.role === 'user' ? 'items-end' : 'items-start'"
                >
                  <div
                    class="flex gap-1 opacity-100 transition-opacity"
                    :class="msg.role === 'user' ? 'flex-row-reverse' : 'flex-row'"
                  >
                    <button
                      type="button"
                      @click="setReplyMessage(msg)"
                      class="p-1.5 rounded-lg transition-colors"
                      :class="msg.role === 'user' ? 'hover:bg-emerald-100 text-emerald-600' : 'hover:bg-gray-100 text-gray-500'"
                      title="Reply"
                    >
                      <Reply class="w-3.5 h-3.5" />
                    </button>
                    <button
                      type="button"
                      @click="openForwardPicker(msg)"
                      class="p-1.5 rounded-lg transition-colors"
                      :class="msg.role === 'user' ? 'hover:bg-emerald-100 text-emerald-600' : 'hover:bg-gray-100 text-gray-500'"
                      title="Forward"
                    >
                      <Forward class="w-3.5 h-3.5" />
                    </button>
                    <button
                      type="button"
                      @click="openCreateTicketFromMessage(msg)"
                      class="p-1.5 rounded-lg transition-colors"
                      :class="msg.role === 'user' ? 'hover:bg-emerald-100 text-emerald-600' : 'hover:bg-gray-100 text-gray-500'"
                      title="Create ticket"
                    >
                      <Ticket class="w-3.5 h-3.5" />
                    </button>
                    <button
                      type="button"
                      @click="toggleFavorite(msg)"
                      class="p-1.5 rounded-lg transition-colors"
                      :class="msg.role === 'user' ? 'hover:bg-emerald-100 text-emerald-600' : 'hover:bg-gray-100 text-gray-500'"
                      title="Favorite"
                    >
                      <Star class="w-3.5 h-3.5" />
                    </button>
                    <button
                      type="button"
                      @click="copyMessage(msg.content)"
                      class="p-1.5 rounded-lg transition-colors"
                      :class="msg.role === 'user' ? 'hover:bg-emerald-100 text-emerald-600' : 'hover:bg-gray-100 text-gray-500'"
                      title="Copy"
                    >
                      <Copy class="w-3.5 h-3.5" />
                    </button>
                  </div>

                  <div
                    class="rounded-2xl pl-4 pr-4 py-3 text-sm leading-relaxed"
                    :class="msg.role === 'user'
                      ? 'bg-emerald-600 text-white rounded-tr-sm'
                      : 'bg-white text-gray-800 shadow-sm border border-gray-100 rounded-tl-sm'"
                  >
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
                      <LinkIcon class="h-3.5 w-3.5 text-gray-400" />
                      <p class="min-w-0 flex-1 truncate text-gray-600">{{ url }}</p>
                      <button type="button" class="rounded p-1 hover:bg-gray-100" title="Copy link" @click="copyLink(url)">
                        <Copy class="h-3.5 w-3.5 text-gray-500" />
                      </button>
                      <button type="button" class="rounded p-1 hover:bg-gray-100" title="Open link" @click="openLink(url)">
                        <ExternalLink class="h-3.5 w-3.5 text-gray-500" />
                      </button>
                    </div>
                  </div>
                  <div
                    v-if="forwardingMessageId === msg.id"
                    class="w-full rounded-lg border border-gray-200 bg-white p-2 text-xs"
                  >
                    <p class="mb-1 font-medium text-gray-700">Forward to another chat</p>
                    <div class="flex items-center gap-2">
                      <select
                        v-model="forwardTargetSessionId"
                        class="min-w-0 flex-1 rounded border border-gray-200 px-2 py-1"
                      >
                        <option :value="null">Select destination chat</option>
                        <option
                          v-for="session in sortedSessions.filter((s) => s.id !== activeSession?.id)"
                          :key="session.id"
                          :value="session.id"
                        >
                          {{ session.title || `Chat #${session.id}` }}
                        </option>
                      </select>
                      <button type="button" class="rounded bg-emerald-600 px-2 py-1 text-white hover:bg-emerald-700" @click="forwardMessage(msg)">Forward</button>
                      <button type="button" class="rounded px-2 py-1 text-gray-500 hover:bg-gray-100" @click="forwardingMessageId = null">Cancel</button>
                    </div>
                  </div>
                </div>
              </div>

              <div v-if="isSending" class="flex gap-3">
                <div class="w-8 h-8 rounded-full bg-emerald-600 flex-shrink-0 flex items-center justify-center">
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

            <div
              class="bg-white border-t border-gray-200 p-4"
              :class="isDragging ? 'ring-2 ring-emerald-400 ring-inset' : ''"
              @dragover="handleDragOver"
              @dragleave="handleDragLeave"
              @drop="handleDrop"
            >
              <div v-if="attachedFiles.length > 0" class="flex flex-wrap gap-2 mb-3 max-w-4xl mx-auto">
                <div
                  v-for="(f, i) in attachedFiles"
                  :key="`${f.name}-${f.size}`"
                  class="flex items-center gap-2 bg-gray-100 rounded-lg overflow-hidden"
                >
                  <img
                    v-if="f.type.startsWith('image/')"
                    :src="getFilePreviewUrl(f)"
                    :alt="f.name"
                    class="w-12 h-12 object-cover flex-shrink-0"
                  />
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
              <div class="max-w-4xl mx-auto">
                <div v-if="replyToMessage" class="mb-2 flex items-start justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900">
                  <p class="line-clamp-2">Replying to: {{ replyToMessage.content }}</p>
                  <button type="button" @click="clearReplyMessage" class="shrink-0 text-emerald-700 hover:text-emerald-900">Cancel</button>
                </div>
              <div class="relative">
                <div v-if="showEmojiPicker" class="absolute bottom-12 left-0 z-20 rounded-lg border border-gray-200 bg-white p-2 shadow">
                  <div class="flex gap-1">
                    <button
                      v-for="emoji in QUICK_EMOJIS"
                      :key="emoji"
                      type="button"
                      class="rounded px-1.5 py-1 text-base hover:bg-gray-100"
                      @click="insertEmoji(emoji)"
                    >
                      {{ emoji }}
                    </button>
                  </div>
                </div>
              <div class="flex items-end gap-2">
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
                  class="flex-shrink-0 w-12 h-12 text-gray-500 hover:text-emerald-600 hover:bg-emerald-50 rounded-xl flex items-center justify-center transition-colors"
                  title="Attach file (PDF, DOCX, Excel, image)"
                >
                  <Paperclip class="w-5 h-5" />
                </button>
                <button
                  type="button"
                  @click="showEmojiPicker = !showEmojiPicker"
                  class="mb-1 inline-flex h-10 w-10 items-center justify-center rounded-xl border border-gray-200 text-gray-500 hover:bg-gray-50 hover:text-emerald-600"
                  title="Emoji"
                >
                  <Smile class="h-4.5 w-4.5" />
                </button>
                <textarea
                  :value="inputMessage"
                  @input="(e) => { inputMessage = (e.target as HTMLTextAreaElement).value }"
                  @keydown="handleKeydown"
                  @paste="handlePaste"
                  placeholder="Type your question 😊 (Enter to send, Shift+Enter for new line)"
                  rows="1"
                  class="flex-1 resize-none border border-gray-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent max-h-32"
                  style="min-height: 48px"
                />
                <button
                  @click="sendMessage"
                  :disabled="(!inputMessage.trim() && !attachedFiles.length) || isSending"
                  class="flex-shrink-0 w-12 h-12 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-xl flex items-center justify-center transition-colors"
                >
                  <Loader2 v-if="isSending" class="w-5 h-5 animate-spin" />
                  <Send v-else class="w-5 h-5" />
                </button>
              </div>
              </div>
              </div>
              <p class="text-xs text-gray-400 text-center mt-2">
                PDF, DOCX, Excel, PNG, JPG — AI will understand content from the Knowledge Base.
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
  @apply text-emerald-600 underline hover:text-emerald-700;
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
