<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { BookMarked, ChevronLeft, ChevronRight, X } from "lucide-vue-next";

import AdminLayout from "@/layouts/AdminLayout.vue";
import * as BRANDING from "@/config/branding";
import { listKnowledgeExtractSyncLogs } from "@/api/cms";
import type { KnowledgeExtractSyncLog } from "@/types";

const route = useRoute();
const router = useRouter();

const logs = ref<KnowledgeExtractSyncLog[]>([]);
const loading = ref(false);
const page = ref(1);
const totalPages = ref(1);
const total = ref(0);
const limit = 20;
const selectedLog = ref<KnowledgeExtractSyncLog | null>(null);

const sectionFilter = computed(() => {
  const s = route.query.section;
  return typeof s === "string" && s.length > 0 ? s : "";
});

function buildParams(): string {
  const params = new URLSearchParams();
  params.set("page", String(page.value));
  params.set("limit", String(limit));
  if (sectionFilter.value) params.set("section", sectionFilter.value);
  return "?" + params.toString();
}

async function load() {
  loading.value = true;
  try {
    const res = await listKnowledgeExtractSyncLogs(buildParams());
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
  return d.toLocaleString("en-US", {
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

function selectLog(log: KnowledgeExtractSyncLog) {
  selectedLog.value = log;
}

function closeDetail() {
  selectedLog.value = null;
}

watch(
  () => route.query.section,
  () => {
    page.value = 1;
    load();
  },
);

function applySectionFilter(section: string) {
  if (!section) {
    const q = { ...route.query };
    delete q.section;
    router.push({ path: route.path, query: q });
    return;
  }
  router.push({ path: route.path, query: { ...route.query, section } });
}

onMounted(load);
</script>

<template>
  <AdminLayout>
    <div class="flex h-[calc(100vh-4rem)] gap-4 p-4">
      <div class="w-80 flex-shrink-0 flex flex-col rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-4 py-3 space-y-2">
          <div class="flex items-center gap-2">
            <BookMarked class="h-4 w-4 text-indigo-600" />
            <div>
              <h2 class="text-sm font-semibold text-slate-900">Knowledge extract log</h2>
              <p class="text-[10px] text-slate-500">{{ BRANDING.ERP_SYSTEM_NAME }} · extract → Vector Store</p>
            </div>
          </div>
          <div class="flex flex-wrap gap-1.5">
            <button
              type="button"
              class="rounded px-2 py-1 text-[10px] font-medium border transition-colors"
              :class="sectionFilter === '' ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200 text-slate-600 hover:bg-slate-50'"
              @click="applySectionFilter('')"
            >
              All
            </button>
            <button
              type="button"
              class="rounded px-2 py-1 text-[10px] font-medium border transition-colors"
              :class="sectionFilter === 'schema' ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200 text-slate-600 hover:bg-slate-50'"
              @click="applySectionFilter('schema')"
            >
              schema
            </button>
            <button
              type="button"
              class="rounded px-2 py-1 text-[10px] font-medium border transition-colors"
              :class="sectionFilter === 'lookup' ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200 text-slate-600 hover:bg-slate-50'"
              @click="applySectionFilter('lookup')"
            >
              lookup
            </button>
            <button
              type="button"
              class="rounded px-2 py-1 text-[10px] font-medium border transition-colors"
              :class="sectionFilter === 'menu_access' ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200 text-slate-600 hover:bg-slate-50'"
              @click="applySectionFilter('menu_access')"
            >
              menu_access
            </button>
          </div>
        </div>

        <div class="flex-1 overflow-y-auto p-3 space-y-2">
          <div v-if="loading" class="py-8 text-center text-xs text-slate-400">Loading…</div>
          <div v-else-if="logs.length === 0" class="py-8 text-center text-xs text-slate-400">No extract logs yet.</div>
          <button
            v-else
            v-for="log in logs"
            :key="log.id"
            type="button"
            class="w-full text-left rounded-lg border border-slate-200 bg-white p-3 shadow-sm transition-all hover:border-slate-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:ring-offset-1"
            :class="selectedLog?.id === log.id ? 'border-indigo-400 ring-2 ring-indigo-200' : ''"
            @click="selectLog(log)"
          >
            <div class="flex items-start justify-between gap-2">
              <span class="text-xs font-medium text-slate-600">{{ formatDateShort(log.createdAt) }}</span>
              <span
                :class="
                  log.status === 'success' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'
                "
                class="rounded-full px-2 py-0.5 text-[10px] font-medium"
              >
                {{ log.status }}
              </span>
            </div>
            <p class="mt-1 text-[10px] font-mono text-indigo-700">{{ log.section }}</p>
            <p class="mt-0.5 text-xs text-slate-500 truncate" :title="log.message ?? ''">
              {{ log.user?.name ?? "—" }}
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
          <span class="text-xs text-slate-500">{{ page }} / {{ totalPages }} ({{ total }})</span>
          <button
            class="rounded p-1.5 text-slate-500 hover:bg-slate-100 disabled:opacity-40"
            :disabled="page >= totalPages"
            @click="nextPage"
          >
            <ChevronRight class="h-4 w-4" />
          </button>
        </div>
      </div>

      <div class="flex-1 min-w-0 rounded-lg border border-slate-200 bg-white shadow-sm flex flex-col overflow-hidden">
        <div v-if="!selectedLog" class="flex flex-1 flex-col items-center justify-center text-slate-400">
          <BookMarked class="h-12 w-12 mb-3 opacity-40" />
          <p class="text-sm">Select a log entry to view details</p>
        </div>

        <template v-else>
          <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <h3 class="text-sm font-semibold text-slate-900">Extract run detail</h3>
            <button type="button" class="rounded p-1.5 text-slate-500 hover:bg-slate-100" @click="closeDetail" aria-label="Close">
              <X class="h-4 w-4" />
            </button>
          </div>
          <div class="flex-1 overflow-y-auto p-4 space-y-4">
            <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
              <div>
                <p class="text-xs font-medium text-slate-500">Time</p>
                <p class="mt-0.5 text-slate-700">{{ formatDate(selectedLog.createdAt) }}</p>
              </div>
              <div>
                <p class="text-xs font-medium text-slate-500">Section</p>
                <p class="mt-0.5 font-mono text-slate-800">{{ selectedLog.section }}</p>
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
                      selectedLog.status === 'success' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'
                    "
                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                  >
                    {{ selectedLog.status }}
                  </span>
                </p>
              </div>
              <div>
                <p class="text-xs font-medium text-slate-500">Triggered by</p>
                <p class="mt-0.5">
                  <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                    {{ triggeredByLabel(selectedLog.triggeredBy) }}
                  </span>
                </p>
              </div>
            </div>

            <div>
              <p class="text-xs font-medium text-slate-500 mb-1">Message</p>
              <pre class="whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-sm text-slate-700 border border-slate-100">{{
                selectedLog.message ?? "—"
              }}</pre>
            </div>

            <div>
              <p class="text-xs font-medium text-slate-500 mb-1">Command output</p>
              <pre class="whitespace-pre-wrap rounded-lg bg-slate-950 p-3 text-xs text-slate-100 max-h-[50vh] overflow-y-auto font-mono">{{
                selectedLog.output ?? "—"
              }}</pre>
            </div>
          </div>
        </template>
      </div>
    </div>
  </AdminLayout>
</template>
