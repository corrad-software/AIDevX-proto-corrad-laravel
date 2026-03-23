<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
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
  listSupportTickets,
  listUsers,
  replySupportTicket,
  updateSupportTicket,
} from "@/api/cms";
import type { SupportTicket, SupportTicketMessage, UserDetail } from "@/types";
import { markdownToSafeHtml } from "@/utils/markdown";
import { ChevronLeft, ChevronRight, MessageSquare, PlusCircle, Save, Search, Ticket, UserCheck } from "lucide-vue-next";

const toast = useToast();
const auth = useAuthStore();

const rows = ref<SupportTicket[]>([]);
const loading = ref(false);
const page = ref(1);
const q = ref("");
const statusFilter = ref("");
const meta = ref<{ page?: number; limit?: number; total?: number; totalPages?: number } | null>(null);

const selected = ref<(SupportTicket & { messages?: SupportTicketMessage[] }) | null>(null);
const detailLoading = ref(false);
const replyText = ref("");
const replying = ref(false);
const assignTo = ref<number | null>(null);
const assigning = ref(false);
const agents = ref<UserDetail[]>([]);
const showCreate = ref(false);

const createForm = ref({
  subject: "",
  description: "",
  customerName: "",
  systemName: "",
  module: "",
  type: "bugs" as "bugs" | "request" | "question",
  priority: "normal" as "low" | "normal" | "high" | "urgent",
});
let searchDebounce: ReturnType<typeof setTimeout> | null = null;

const level = computed(() => auth.user?.userLevel ?? "user");
const normalizedLevel = computed(() => String(level.value ?? "").toLowerCase());
const isLevel4User = computed(() => ["user", "level4", "4"].includes(normalizedLevel.value));
const canCreate = computed(() => isLevel4User.value);
const canAssign = computed(() => level.value === "internal_admin" || level.value === "external_admin" || level.value === "super_admin");
const canRespond = computed(() => ["internal_admin", "external_admin", "super_admin", "agent"].includes(level.value));
const canEditOwn = computed(() => isLevel4User.value);
const canDeleteOwn = computed(() => isLevel4User.value);

/** Read-only labels for L4 ticket form (from profile / linked customer). */
const profileCustomerLabel = computed(() => auth.user?.customerDisplayName?.trim() || auth.user?.customerCode || "—");
const profileSystemLabel = computed(() => auth.user?.systemDisplayName?.trim() || "—");

const selectedDescriptionHtml = computed(() => markdownToSafeHtml(selected.value?.description ?? ""));

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

async function openDetail(ticket: SupportTicket) {
  detailLoading.value = true;
  try {
    const res = await getSupportTicket(ticket.id);
    selected.value = res.data;
    replyText.value = "";
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to load ticket detail");
  } finally {
    detailLoading.value = false;
  }
}

async function loadAgents() {
  try {
    const res = await listUsers();
    agents.value = res.data.filter((u) => (u.userLevel ?? "") === "agent");
  } catch {
    agents.value = [];
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
    });
    toast.success("Ticket created");
    showCreate.value = false;
    createForm.value = {
      subject: "",
      description: "",
      customerName: auth.user?.customerCode ?? "",
      systemName: "",
      module: "",
      type: "bugs",
      priority: "normal",
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
    });
    selected.value = { ...selected.value, ...res.data };
    toast.success("Ticket updated");
    await load();
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

async function sendReply() {
  if (!selected.value || !replyText.value.trim()) return;
  replying.value = true;
  try {
    await replySupportTicket(selected.value.id, { message: replyText.value.trim() });
    replyText.value = "";
    await openDetail(selected.value);
    await load();
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to send reply");
  } finally {
    replying.value = false;
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

onMounted(async () => {
  await auth.refreshUser();
  createForm.value.customerName = auth.user?.customerDisplayName?.trim() || auth.user?.customerCode || "";
  createForm.value.systemName = auth.user?.systemDisplayName?.trim() || "";
  if (canCreate.value) {
    showCreate.value = true;
  }
  await load();
  if (canAssign.value) {
    await loadAgents();
  }
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
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-7xl px-4 py-6">
      <div class="mb-4 flex items-center justify-between">
        <h1 class="flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-slate-100">
          <Ticket class="h-6 w-6 text-[var(--accent-600)]" />
          Ticket
        </h1>
        <button v-if="canCreate" type="button" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white" @click="showCreate = !showCreate">
          <PlusCircle class="h-4 w-4" />
          {{ showCreate ? "Hide Form" : "Create Ticket" }}
        </button>
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
          <input v-model="createForm.customerName" class="rounded border px-3 py-2 text-sm dark:bg-slate-950" placeholder="Customer" />
          <input v-model="createForm.systemName" class="rounded border px-3 py-2 text-sm dark:bg-slate-950" placeholder="System Name" />
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
          <div class="md:col-span-2">
            <MarkdownEditor v-model="createForm.description" :rows="8" :enable-image-upload="true" placeholder="Describe your issue..." />
          </div>
        </div>
        <button class="mt-3 inline-flex items-center gap-2 rounded bg-blue-600 px-3 py-2 text-sm font-medium text-white" @click="submitCreate">
          <Save class="h-4 w-4" />
          Submit Ticket
        </button>
      </div>

      <div class="grid gap-4 lg:grid-cols-[380px_1fr]">
        <div class="rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
          <div class="flex items-center gap-2 border-b p-3">
            <div class="relative flex-1">
              <Search class="absolute left-2 top-2.5 h-4 w-4 text-slate-400" />
              <input v-model="q" class="w-full rounded border pl-8 pr-2 py-2 text-sm dark:bg-slate-950" placeholder="Search ticket..." @keyup.enter="load" />
            </div>
            <select v-model="statusFilter" class="rounded border px-2 py-2 text-sm dark:bg-slate-950" @change="load">
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
              No tickets
              <span v-if="canCreate" class="block pt-1 text-xs text-slate-400">Create ticket using the form above, then it will appear in this list.</span>
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
            </div>

            <div v-if="canAssign" class="rounded border p-3">
              <p class="mb-2 text-xs font-semibold text-slate-600">Assign to Agent</p>
              <div class="flex gap-2">
                <select v-model="assignTo" class="flex-1 rounded border px-2 py-2 text-sm dark:bg-slate-950">
                  <option :value="null">Select agent</option>
                  <option v-for="a in agents" :key="a.id" :value="a.id">{{ a.name }} ({{ a.email }})</option>
                </select>
                <button class="inline-flex items-center gap-1 rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50" :disabled="assigning || !assignTo" @click="assignTicket">
                  <UserCheck class="h-4 w-4" /> Assign
                </button>
              </div>
            </div>

            <div v-if="canEditOwn && selected.createdByUserId === auth.user?.id && (selected.status === 'new' || selected.status === 'pending_requestor')" class="rounded border p-3">
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
              <MarkdownEditor v-model="selected.description" :rows="8" :enable-image-upload="true" placeholder="Describe your issue..." />
              <div class="mt-2 flex gap-2">
                <button class="rounded bg-slate-900 px-3 py-1.5 text-xs text-white" @click="saveOwnTicket">Save</button>
                <button v-if="canDeleteOwn && selected.status === 'new'" class="rounded border border-rose-300 px-3 py-1.5 text-xs text-rose-600" @click="removeOwnTicket">Delete</button>
              </div>
            </div>

            <div class="rounded border p-3">
              <p class="mb-2 text-xs font-semibold text-slate-600">Conversation</p>
              <div class="mb-3 max-h-72 space-y-2 overflow-y-auto">
                <div v-for="m in selected.messages || []" :key="m.id" class="rounded border bg-slate-50 p-2 text-sm dark:bg-slate-800">
                  <p class="text-xs text-slate-500">{{ m.user?.name || "User" }} · {{ new Date(m.createdAt).toLocaleString() }}</p>
                  <p>{{ m.message }}</p>
                </div>
              </div>
              <div v-if="canRespond || selected.createdByUserId === auth.user?.id" class="flex gap-2">
                <input v-model="replyText" class="flex-1 rounded border px-2 py-2 text-sm dark:bg-slate-950" placeholder="Type reply..." />
                <button class="inline-flex items-center gap-1 rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50" :disabled="replying || !replyText.trim()" @click="sendReply">
                  <MessageSquare class="h-4 w-4" /> Send
                </button>
                <button v-if="canRespond || selected.createdByUserId === auth.user?.id" class="rounded border px-3 py-2 text-xs" @click="closeTicketNow">Close</button>
              </div>
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
</style>
