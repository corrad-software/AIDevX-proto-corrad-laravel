<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { RefreshCw, ChevronLeft, ChevronRight, Loader2, X } from "lucide-vue-next";

import AdminLayout from "@/layouts/AdminLayout.vue";
import { listDesk365SyncLogs, syncDesk365Tickets } from "@/api/cms";
import { useAuthStore } from "@/stores/auth";
import { useToast } from "@/composables/useToast";
import type { Desk365SyncLog } from "@/types";

const toast = useToast();
const auth = useAuthStore();

/** Manual sync is restricted to knowledge managers; L1–L4 can browse logs. */
const canSyncDesk365 = computed(() => auth.user?.permissions?.includes("knowledge.manage") ?? false);
const logs = ref<Desk365SyncLog[]>([]);
const loading = ref(false);
const syncing = ref(false);
const page = ref(1);
const totalPages = ref(1);
const total = ref(0);
const limit = 20;
const selectedLog = ref<Desk365SyncLog | null>(null);
const expandedTicketIndex = ref<number | null>(null);

const ticketListForDetail = computed(() => selectedLog.value?.uploadedTicketDetails ?? []);
const hasTicketDetails = computed(() => (ticketListForDetail.value?.length ?? 0) > 0);

function toggleTicketExpand(i: number) {
  expandedTicketIndex.value = expandedTicketIndex.value === i ? null : i;
}

function getModuleCount(moduleName: string): number | null {
  const counts = selectedLog.value?.uploadedModuleCounts;
  if (!counts) return null;
  if (Array.isArray(counts)) {
    const found = counts.find((c: { module: string; count: number }) => c.module === moduleName);
    return found ? found.count : null;
  }
  return (counts as Record<string, number>)[moduleName] ?? null;
}

function buildParams(): string {
  const params = new URLSearchParams();
  params.set("page", String(page.value));
  params.set("limit", String(limit));
  return "?" + params.toString();
}

async function load() {
  loading.value = true;
  try {
    const res = await listDesk365SyncLogs(buildParams());
    logs.value = res.data;
    const meta = res.meta || {};
    totalPages.value = (meta.totalPages as number) || 1;
    total.value = (meta.total as number) || 0;
  } catch {
    logs.value = [];
  } finally {
    loading.value = false;
  }
}

async function runSync() {
  syncing.value = true;
  try {
    const res = await syncDesk365Tickets();
    const d = res.data;
    if (d.success) {
      toast.success("Sync selesai", d.message);
    } else {
      toast.error("Sync gagal", d.message);
    }
    page.value = 1;
    await load();
  } catch (e) {
    toast.error("Sync gagal", e instanceof Error ? e.message : "Ralat tidak diketahui");
  } finally {
    syncing.value = false;
  }
}

function prevPage() {
  if (page.value > 1) {
    page.value--;
    load();
  }
}

function nextPage() {
  if (page.value < totalPages.value) {
    page.value++;
    load();
  }
}

function formatDate(dateStr: string): string {
  const d = new Date(dateStr);
  return d.toLocaleString("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function formatDateShort(dateStr: string): string {
  const d = new Date(dateStr);
  return d.toLocaleString("ms-MY", {
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function triggeredByLabel(v: string): string {
  const map: Record<string, string> = {
    manual: "Manual",
    scheduler: "Scheduler",
    api: "API",
  };
  return map[v] || v;
}

function selectLog(log: Desk365SyncLog) {
  selectedLog.value = log;
  expandedTicketIndex.value = null;
}

function closeDetail() {
  selectedLog.value = null;
}

onMounted(load);
</script>

<template>
  <AdminLayout>
    <div class="flex h-[calc(100vh-4rem)] gap-4 p-4">
      <!-- Panel 1: Card list -->
      <div class="w-80 flex-shrink-0 flex flex-col rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
          <div class="flex items-center gap-2">
            <RefreshCw class="h-4 w-4 text-amber-600" />
            <h2 class="text-sm font-semibold text-slate-900">Sync History</h2>
          </div>
          <button
            v-if="canSyncDesk365"
            class="flex items-center gap-1.5 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-slate-800 disabled:opacity-50"
            :disabled="syncing"
            @click="runSync"
          >
            <Loader2 v-if="syncing" class="h-3.5 w-3.5 animate-spin" />
            <RefreshCw v-else class="h-3.5 w-3.5" />
            Sync Now
          </button>
        </div>

        <div class="flex-1 overflow-y-auto p-3 space-y-2">
          <div v-if="loading" class="py-8 text-center text-xs text-slate-400">Loading...</div>
          <div v-else-if="logs.length === 0" class="py-8 text-center text-xs text-slate-400">Tiada log sync.</div>
          <button
            v-else
            v-for="log in logs"
            :key="log.id"
            type="button"
            class="w-full text-left rounded-lg border border-slate-200 bg-white p-3 shadow-sm transition-all hover:border-slate-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-1"
            :class="selectedLog?.id === log.id ? 'border-slate-400 ring-2 ring-slate-300' : ''"
            @click="selectLog(log)"
          >
            <div class="flex items-start justify-between gap-2">
              <span class="text-xs font-medium text-slate-600">{{ formatDateShort(log.createdAt) }}</span>
              <span
                :class="
                  log.status === 'success'
                    ? 'bg-emerald-100 text-emerald-700'
                    : 'bg-rose-100 text-rose-700'
                "
                class="rounded-full px-2 py-0.5 text-[10px] font-medium"
              >
                {{ log.status }}
              </span>
            </div>
            <p class="mt-1 text-xs text-slate-500 truncate" :title="log.message ?? ''">
              {{ log.user?.name ?? "—" }} · {{ triggeredByLabel(log.triggeredBy) }}
            </p>
            <p class="mt-0.5 text-[11px] text-slate-400 line-clamp-2">
              {{ log.message ?? "—" }}
            </p>
          </button>
        </div>

        <div v-if="totalPages > 1" class="flex items-center justify-between border-t border-slate-100 px-3 py-2">
          <button
            class="rounded p-1.5 text-slate-500 hover:bg-slate-100 disabled:opacity-40"
            :disabled="page <= 1"
            @click="prevPage"
          >
            <ChevronLeft class="h-4 w-4" />
          </button>
          <span class="text-xs text-slate-500">{{ page }} / {{ totalPages }}</span>
          <button
            class="rounded p-1.5 text-slate-500 hover:bg-slate-100 disabled:opacity-40"
            :disabled="page >= totalPages"
            @click="nextPage"
          >
            <ChevronRight class="h-4 w-4" />
          </button>
        </div>
      </div>

      <!-- Panel 2: Detail -->
      <div
        class="flex-1 min-w-0 rounded-lg border border-slate-200 bg-white shadow-sm flex flex-col overflow-hidden"
      >
        <div v-if="!selectedLog" class="flex flex-1 flex-col items-center justify-center text-slate-400">
          <RefreshCw class="h-12 w-12 mb-3 opacity-40" />
          <p class="text-sm">Klik kad untuk lihat detail</p>
        </div>

        <template v-else>
          <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <h3 class="text-sm font-semibold text-slate-900">Detail Sync</h3>
            <button
              type="button"
              class="rounded p-1.5 text-slate-500 hover:bg-slate-100"
              @click="closeDetail"
              aria-label="Tutup"
            >
              <X class="h-4 w-4" />
            </button>
          </div>
          <div class="flex-1 overflow-y-auto p-4 space-y-4">
            <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
              <div>
                <p class="text-xs font-medium text-slate-500">Time</p>
                <p class="mt-0.5 text-slate-700">{{ formatDate(selectedLog.createdAt) }}</p>
              </div>
              <div>
                <p class="text-xs font-medium text-slate-500">Triggered By</p>
                <p class="mt-0.5">
                  <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                    {{ triggeredByLabel(selectedLog.triggeredBy) }}
                  </span>
                </p>
              </div>
              <div>
                <p class="text-xs font-medium text-slate-500">User</p>
                <p class="mt-0.5 text-slate-700">{{ selectedLog.user?.name ?? "—" }}</p>
              </div>
              <div>
                <p class="text-xs font-medium text-slate-500">Status</p>
                <p class="mt-0.5">
                  <span
                    :class="
                      selectedLog.status === 'success'
                        ? 'bg-emerald-100 text-emerald-700'
                        : 'bg-rose-100 text-rose-700'
                    "
                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                  >
                    {{ selectedLog.status }}
                  </span>
                </p>
              </div>
              <div>
                <p class="text-xs font-medium text-slate-500">Tickets</p>
                <p class="mt-0.5 font-mono text-slate-700">{{ selectedLog.totalTickets }}</p>
              </div>
              <div>
                <p class="text-xs font-medium text-slate-500">Modules</p>
                <p class="mt-0.5 font-mono text-slate-700">{{ selectedLog.modulesSynced }}</p>
              </div>
              <div>
                <p class="text-xs font-medium text-slate-500">Uploaded</p>
                <p class="mt-0.5 font-mono text-emerald-600">{{ selectedLog.uploaded }}</p>
              </div>
              <div>
                <p class="text-xs font-medium text-slate-500">Failed</p>
                <p class="mt-0.5 font-mono text-rose-600">{{ selectedLog.failed }}</p>
              </div>
            </div>
            <!-- Uploaded modules listing with ticket count -->
            <div v-if="selectedLog.uploadedModules?.length">
              <p class="text-xs font-medium text-slate-500 mb-2">Uploaded Modules</p>
              <ul class="rounded-lg border border-slate-200 divide-y divide-slate-100">
                <li
                  v-for="(mod, i) in selectedLog.uploadedModules"
                  :key="i"
                  class="flex items-center justify-between gap-2 px-3 py-2 text-sm text-slate-700 bg-white hover:bg-slate-50"
                >
                  <div class="flex items-center gap-2">
                    <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-xs font-medium">
                      {{ i + 1 }}
                    </span>
                    {{ mod }}
                  </div>
                  <span
                    v-if="getModuleCount(mod) != null"
                    class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"
                  >
                    {{ getModuleCount(mod) }} ticket
                  </span>
                </li>
              </ul>
            </div>

            <!-- Ticket list (collapsible when details available) -->
            <div>
              <p class="text-xs font-medium text-slate-500 mb-2">Senarai Ticket</p>
              <div
                v-if="hasTicketDetails"
                class="rounded-lg border border-slate-200 overflow-hidden max-h-64 overflow-y-auto"
              >
                <div
                  v-for="(t, i) in ticketListForDetail"
                  :key="i"
                  class="border-b border-slate-100 last:border-b-0"
                >
                  <button
                    type="button"
                    class="w-full flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 text-left"
                    @click="toggleTicketExpand(i)"
                  >
                    <ChevronRight
                      class="w-4 h-4 flex-shrink-0 transition-transform"
                      :class="expandedTicketIndex === i ? 'rotate-90' : ''"
                    />
                    <span class="w-6 h-6 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center text-xs font-medium flex-shrink-0">
                      {{ i + 1 }}
                    </span>
                    <span class="font-mono">#{{ t.ticketNumber }}</span>
                    <span v-if="t.subject" class="truncate text-slate-500 ml-1">{{ t.subject }}</span>
                  </button>
                  <div
                    v-if="expandedTicketIndex === i"
                    class="px-3 pb-3 pt-0 pl-12 bg-slate-50 text-sm space-y-2"
                  >
                    <p v-if="t.subject"><span class="font-medium text-slate-500">Subjek:</span> {{ t.subject }}</p>
                    <p v-if="t.module"><span class="font-medium text-slate-500">Modul:</span> {{ t.module }}</p>
                    <p v-if="t.assignedAgent"><span class="font-medium text-slate-500">Agent Assigned:</span> {{ t.assignedAgent }}</p>
                    <p v-if="t.status"><span class="font-medium text-slate-500">Status:</span> {{ t.status }}</p>
                    <p v-if="t.type"><span class="font-medium text-slate-500">Type:</span> {{ t.type }}</p>
                    <p v-if="t.priority"><span class="font-medium text-slate-500">Priority:</span> {{ t.priority }}</p>
                    <p v-if="t.contactName"><span class="font-medium text-slate-500">Contact:</span> {{ t.contactName }}</p>
                    <p v-if="t.companyName"><span class="font-medium text-slate-500">Customer:</span> {{ t.companyName }}</p>
                    <p v-if="t.description" class="whitespace-pre-wrap text-slate-600">{{ t.description }}</p>
                  </div>
                </div>
              </div>
              <div
                v-else-if="selectedLog.uploadedTicketNumbers?.length"
                class="rounded-lg border border-slate-200 overflow-hidden"
              >
                <ul class="divide-y divide-slate-100 max-h-64 overflow-y-auto bg-white">
                  <li
                    v-for="(num, i) in selectedLog.uploadedTicketNumbers"
                    :key="i"
                    class="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                  >
                    <span class="w-6 h-6 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center text-xs font-medium flex-shrink-0">
                      {{ i + 1 }}
                    </span>
                    <span class="font-mono">#{{ num }}</span>
                  </li>
                </ul>
              </div>
              <div
                v-else-if="selectedLog.totalTickets > 0"
                class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500"
              >
                {{ selectedLog.totalTickets }} ticket disegerakkan. Senarai detail hanya tersedia untuk sync selepas kemas kini. Jalankan <strong>Sync Now</strong> untuk rekod baru.
              </div>
              <div v-else class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">
                Tiada ticket dalam sync ini.
              </div>
            </div>

            <div>
              <p class="text-xs font-medium text-slate-500">Message</p>
              <p class="mt-1 whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
                {{ selectedLog.message ?? "—" }}
              </p>
            </div>
          </div>
        </template>
      </div>
    </div>
  </AdminLayout>
</template>
