<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import {
  Activity,
  BarChart3,
  Loader2,
  RefreshCw,
  Ticket,
  Zap,
  Users,
  Clock,
  AlertCircle,
  MessageSquare,
} from "lucide-vue-next";

import AdminLayout from "@/layouts/AdminLayout.vue";
import * as BRANDING from "@/config/branding";
import { getTicketMonitoring } from "@/api/cms";
import { useToast } from "@/composables/useToast";
import type { TicketMonitoringPayload } from "@/types";

const router = useRouter();
const toast = useToast();

const data = ref<TicketMonitoringPayload | null>(null);
const loading = ref(true);

function barPct(count: number, rows: { count: number }[]): string {
  const m = Math.max(1, ...rows.map((r) => r.count));
  return `${Math.round((count / m) * 100)}%`;
}

async function load() {
  loading.value = true;
  try {
    const res = await getTicketMonitoring();
    data.value = res.data as TicketMonitoringPayload;
  } catch {
    toast.error("Gagal memuatkan pemantauan tiket");
    data.value = null;
  } finally {
    loading.value = false;
  }
}

function formatWhen(iso: string | null | undefined): string {
  if (!iso) return "—";
  try {
    return new Date(iso).toLocaleString("ms-MY", {
      dateStyle: "medium",
      timeStyle: "short",
    });
  } catch {
    return iso;
  }
}

onMounted(load);
</script>

<template>
  <AdminLayout>
    <div class="max-w-6xl mx-auto p-6 space-y-6">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 class="text-2xl font-bold text-slate-900 flex items-center gap-2">
            <BarChart3 class="w-7 h-7 text-teal-600" />
            Ticket monitoring
          </h1>
          <p class="mt-1 text-sm text-slate-500">
            <span class="font-medium text-slate-700">{{ BRANDING.PLATFORM_FULL_NAME }}</span>
            — pantau tiket <strong>Desk365</strong> (rekod DB selepas sync) dan <strong>tiket dalaman</strong> ({{ BRANDING.ERP_SYSTEM_NAME }}), termasuk status, agen/ejen bertugas, dan kesihatan dokumen di Vector Store.
          </p>
          <p v-if="data?.generatedAt" class="mt-1 text-xs text-slate-400">
            Data: {{ formatWhen(data.generatedAt) }}
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            @click="load"
            :disabled="loading"
          >
            <Loader2 v-if="loading" class="h-4 w-4 animate-spin" />
            <RefreshCw v-else class="h-4 w-4" />
            Muat semula
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-lg bg-orange-600 px-3 py-2 text-sm font-medium text-white hover:bg-orange-700"
            @click="router.push('/admin/platform/desk365')"
          >
            Desk365 log
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-lg bg-teal-700 px-3 py-2 text-sm font-medium text-white hover:bg-teal-800"
            @click="router.push('/admin/platform/ticket-log')"
          >
            Ticket log
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            @click="router.push('/admin/kerisi/ticket')"
          >
            <Ticket class="h-4 w-4" />
            Senarai tiket
          </button>
        </div>
      </div>

      <div v-if="loading && !data" class="flex justify-center py-20">
        <Loader2 class="h-10 w-10 animate-spin text-teal-600" />
      </div>

      <template v-else-if="data">
        <!-- Ringkasan utama -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
          <div class="rounded-xl border border-orange-200 bg-orange-50/80 p-4 text-center shadow-sm">
            <p class="text-3xl font-bold text-orange-800">{{ data.desk365Synced.total }}</p>
            <p class="text-xs font-medium text-orange-900/80 mt-1">Tiket Desk365 (DB sync)</p>
            <p class="text-[10px] text-orange-800/70 mt-0.5">Selepas sync ke AI</p>
          </div>
          <div class="rounded-xl border border-teal-200 bg-teal-50/80 p-4 text-center shadow-sm">
            <p class="text-3xl font-bold text-teal-800">{{ data.internal.total }}</p>
            <p class="text-xs font-medium text-teal-900/80 mt-1">Tiket dalaman</p>
            <p class="text-[10px] text-teal-800/70 mt-0.5">{{ BRANDING.ERP_SYSTEM_NAME }} — tiket dalaman KEHSA</p>
          </div>
          <div class="rounded-xl border border-amber-200 bg-white p-4 text-center shadow-sm">
            <p class="text-3xl font-bold text-amber-700">{{ data.internal.open }}</p>
            <p class="text-xs text-slate-600 mt-1 flex items-center justify-center gap-1">
              <Activity class="w-3.5 h-3.5" /> Aktif (bukan tutup/selesai)
            </p>
          </div>
          <div class="rounded-xl border border-rose-200 bg-white p-4 text-center shadow-sm">
            <p class="text-3xl font-bold text-rose-600">{{ data.internal.unassigned }}</p>
            <p class="text-xs text-slate-600 mt-1 flex items-center justify-center gap-1">
              <AlertCircle class="w-3.5 h-3.5" /> Belum ditugaskan
            </p>
          </div>
        </div>

        <!-- Tiket terbuka mengikut ejen (Desk365 vs dalaman) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div class="rounded-xl border border-orange-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-orange-900 mb-1 flex items-center gap-2">
              <Users class="w-4 h-4" />
              Tiket terbuka — Desk365 (ikut ejen)
            </h2>
            <p class="text-xs text-slate-500 mb-4">Data daripada jadual sync; status bukan tutup/selesai.</p>
            <div class="space-y-2">
              <div
                v-for="row in (data.desk365Synced.openByAgent ?? [])"
                :key="'d365-a-' + row.label"
                class="flex items-center gap-3"
              >
                <span class="w-32 shrink-0 text-xs text-slate-600 truncate" :title="row.label">{{ row.label }}</span>
                <div class="flex-1 h-7 rounded-md bg-slate-100 overflow-hidden">
                  <div
                    class="h-full rounded-md bg-orange-500 transition-all min-w-[4px]"
                    :style="{ width: barPct(row.count, data.desk365Synced.openByAgent ?? []) }"
                  />
                </div>
                <span class="w-8 text-right text-sm font-semibold text-slate-800">{{ row.count }}</span>
              </div>
              <p v-if="(data.desk365Synced.openByAgent ?? []).length === 0" class="text-sm text-slate-500">
                Tiada tiket terbuka atau belum ada sync Desk365.
              </p>
            </div>
          </div>
          <div class="rounded-xl border border-teal-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-teal-900 mb-1 flex items-center gap-2">
              <Users class="w-4 h-4" />
              Tiket terbuka — dalaman (ikut penerima tugasan)
            </h2>
            <p class="text-xs text-slate-500 mb-4">Mengikut e-mel / nama pengguna yang ditugaskan.</p>
            <div class="space-y-2">
              <div
                v-for="row in (data.internal.openByAssignee ?? [])"
                :key="'int-a-' + row.label"
                class="flex items-center gap-3"
              >
                <span class="w-32 shrink-0 text-xs text-slate-600 truncate" :title="row.label">{{ row.label }}</span>
                <div class="flex-1 h-7 rounded-md bg-slate-100 overflow-hidden">
                  <div
                    class="h-full rounded-md bg-teal-500 transition-all min-w-[4px]"
                    :style="{ width: barPct(row.count, data.internal.openByAssignee ?? []) }"
                  />
                </div>
                <span class="w-8 text-right text-sm font-semibold text-slate-800">{{ row.count }}</span>
              </div>
              <p v-if="(data.internal.openByAssignee ?? []).length === 0" class="text-sm text-slate-500">Tiada tiket terbuka atau belum ditugaskan.</p>
            </div>
          </div>
        </div>

        <!-- Sesi chat — pengguna teratas -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <h2 class="text-sm font-semibold text-slate-800 mb-1 flex items-center gap-2">
            <MessageSquare class="w-4 h-4 text-sky-600" />
            Sesi chat — pengguna teratas (SELAR / AINA)
          </h2>
          <p class="text-xs text-slate-500 mb-4">Bilangan sesi chat mengikut pemilik sesi.</p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="space-y-2">
              <div
                v-for="row in (data.chatActivity?.sessionsByUser ?? [])"
                :key="'chat-' + row.label"
                class="flex items-center gap-3"
              >
                <span class="flex-1 text-xs text-slate-700 truncate" :title="row.label">{{ row.label }}</span>
                <span class="text-sm font-semibold text-sky-700">{{ row.count }}</span>
              </div>
              <p v-if="(data.chatActivity?.sessionsByUser ?? []).length === 0" class="text-sm text-slate-500">Tiada sesi chat direkodkan.</p>
            </div>
            <div class="rounded-lg bg-slate-50 p-4 text-xs text-slate-600 leading-relaxed">
              <strong class="text-slate-800">Nota:</strong> carta tiket Desk365 menggunakan medan <em>assigned_agent</em> daripada sync;
              tiket dalaman menggunakan pengguna yang ditugaskan dalam {{ BRANDING.ERP_SYSTEM_NAME }}.
            </div>
          </div>
        </div>

        <!-- Aliran 7 hari + AI dokumen -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-slate-800 uppercase tracking-wide flex items-center gap-2 mb-4">
              <Clock class="w-4 h-4 text-slate-500" />
              Aktiviti tiket dalaman (7 hari)
            </h2>
            <div class="grid grid-cols-2 gap-4">
              <div class="rounded-lg bg-slate-50 p-4 text-center">
                <p class="text-2xl font-bold text-slate-900">{{ data.internal.createdLast7Days }}</p>
                <p class="text-xs text-slate-500 mt-1">Baharu dicipta</p>
              </div>
              <div class="rounded-lg bg-emerald-50 p-4 text-center">
                <p class="text-2xl font-bold text-emerald-700">{{ data.internal.closedLast7Days }}</p>
                <p class="text-xs text-slate-600 mt-1">Ditutup</p>
              </div>
            </div>
          </div>
          <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-slate-800 uppercase tracking-wide flex items-center gap-2 mb-4">
              <Zap class="w-4 h-4 text-amber-500" />
              Dokumen tiket di Vector Store (AI)
            </h2>
            <div class="space-y-3 text-sm">
              <div class="flex justify-between items-center border-b border-slate-100 pb-2">
                <span class="text-orange-800 font-medium">Desk365</span>
                <span class="text-slate-600">
                  {{ data.aiKnowledge.desk365UploadedCount }} / {{ data.aiKnowledge.desk365DocumentCount }} dokumen siap
                </span>
              </div>
              <div class="flex justify-between items-center">
                <span class="text-teal-800 font-medium">Dalaman</span>
                <span class="text-slate-600">
                  {{ data.aiKnowledge.internalUploadedCount }} / {{ data.aiKnowledge.internalDocumentCount }} dokumen siap
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Sync terakhir -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="rounded-xl border border-orange-100 bg-orange-50/50 p-4">
            <p class="text-xs font-semibold text-orange-900 uppercase">Sync Desk365 terakhir</p>
            <template v-if="data.lastSync.desk365">
              <p class="mt-2 text-sm text-slate-700">{{ formatWhen(data.lastSync.desk365.createdAt) }}</p>
              <p class="text-xs mt-1">
                <span
                  class="rounded-full px-2 py-0.5 font-medium"
                  :class="data.lastSync.desk365.status === 'success' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'"
                >
                  {{ data.lastSync.desk365.status }}
                </span>
                <span class="text-slate-500 ml-2">
                  {{ data.lastSync.desk365.totalTickets }} tiket · muat naik {{ data.lastSync.desk365.uploaded }}
                  <span v-if="data.lastSync.desk365.failed"> · gagal {{ data.lastSync.desk365.failed }}</span>
                </span>
              </p>
            </template>
            <p v-else class="mt-2 text-sm text-slate-500">Belum ada log sync.</p>
          </div>
          <div class="rounded-xl border border-teal-100 bg-teal-50/50 p-4">
            <p class="text-xs font-semibold text-teal-900 uppercase">Sync tiket dalaman terakhir</p>
            <template v-if="data.lastSync.internal">
              <p class="mt-2 text-sm text-slate-700">{{ formatWhen(data.lastSync.internal.createdAt) }}</p>
              <p class="text-xs mt-1">
                <span
                  class="rounded-full px-2 py-0.5 font-medium"
                  :class="data.lastSync.internal.status === 'success' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'"
                >
                  {{ data.lastSync.internal.status }}
                </span>
                <span class="text-slate-500 ml-2">
                  {{ data.lastSync.internal.totalTickets }} tiket · muat naik {{ data.lastSync.internal.uploaded }}
                  <span v-if="data.lastSync.internal.failed"> · gagal {{ data.lastSync.internal.failed }}</span>
                </span>
              </p>
            </template>
            <p v-else class="mt-2 text-sm text-slate-500">Belum ada log sync.</p>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Dalaman: status -->
          <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-teal-900 mb-4 flex items-center gap-2">
              <Users class="w-4 h-4" />
              Tiket dalaman — mengikut status
            </h2>
            <div class="space-y-2">
              <div v-for="row in data.internal.byStatus" :key="'is-' + row.label" class="flex items-center gap-3">
                <span class="w-28 shrink-0 text-xs text-slate-600 truncate" :title="row.label">{{ row.label }}</span>
                <div class="flex-1 h-7 rounded-md bg-slate-100 overflow-hidden">
                  <div
                    class="h-full rounded-md bg-teal-500 transition-all min-w-[4px]"
                    :style="{ width: barPct(row.count, data.internal.byStatus) }"
                  />
                </div>
                <span class="w-8 text-right text-sm font-semibold text-slate-800">{{ row.count }}</span>
              </div>
              <p v-if="data.internal.byStatus.length === 0" class="text-sm text-slate-500">Tiada data.</p>
            </div>
          </div>

          <!-- Desk365: status -->
          <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-orange-900 mb-4 flex items-center gap-2">
              <Ticket class="w-4 h-4" />
              Tiket Desk365 (sync) — mengikut status
            </h2>
            <div class="space-y-2">
              <div v-for="row in data.desk365Synced.byStatus" :key="'ds-' + row.label" class="flex items-center gap-3">
                <span class="w-28 shrink-0 text-xs text-slate-600 truncate" :title="row.label">{{ row.label }}</span>
                <div class="flex-1 h-7 rounded-md bg-slate-100 overflow-hidden">
                  <div
                    class="h-full rounded-md bg-orange-500 transition-all min-w-[4px]"
                    :style="{ width: barPct(row.count, data.desk365Synced.byStatus) }"
                  />
                </div>
                <span class="w-8 text-right text-sm font-semibold text-slate-800">{{ row.count }}</span>
              </div>
              <p v-if="data.desk365Synced.byStatus.length === 0" class="text-sm text-slate-500">Tiada rekod sync. Jalankan sync dari Knowledge Base atau Desk365 log.</p>
            </div>
          </div>

          <!-- Dalaman: keutamaan -->
          <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-slate-800 mb-4">Tiket dalaman — keutamaan</h2>
            <div class="flex flex-wrap gap-2">
              <span
                v-for="row in data.internal.byPriority"
                :key="'ip-' + row.label"
                class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs"
              >
                <span class="font-medium text-slate-700">{{ row.label }}</span>
                <span class="rounded-full bg-teal-100 px-2 py-0.5 font-semibold text-teal-800">{{ row.count }}</span>
              </span>
            </div>
          </div>

          <!-- Desk365: keutamaan -->
          <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-slate-800 mb-4">Tiket Desk365 — keutamaan (data sync)</h2>
            <div class="flex flex-wrap gap-2">
              <span
                v-for="row in data.desk365Synced.byPriority"
                :key="'dp-' + row.label"
                class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs"
              >
                <span class="font-medium text-slate-700">{{ row.label }}</span>
                <span class="rounded-full bg-orange-100 px-2 py-0.5 font-semibold text-orange-800">{{ row.count }}</span>
              </span>
            </div>
          </div>

          <!-- Modul dalaman -->
          <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-1">
            <h2 class="text-sm font-semibold text-slate-800 mb-4">Tiket dalaman — modul teratas</h2>
            <div class="space-y-2">
              <div v-for="row in data.internal.byModule" :key="'im-' + row.label" class="flex items-center gap-3">
                <span class="flex-1 text-xs text-slate-700 truncate" :title="row.label">{{ row.label }}</span>
                <span class="text-sm font-semibold text-teal-700">{{ row.count }}</span>
              </div>
            </div>
          </div>

          <!-- Modul Desk365 -->
          <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-1">
            <h2 class="text-sm font-semibold text-slate-800 mb-4">Tiket Desk365 — modul teratas (sync)</h2>
            <div class="space-y-2">
              <div v-for="row in data.desk365Synced.byModule" :key="'dm-' + row.label" class="flex items-center gap-3">
                <span class="flex-1 text-xs text-slate-700 truncate" :title="row.label">{{ row.label }}</span>
                <span class="text-sm font-semibold text-orange-700">{{ row.count }}</span>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>
  </AdminLayout>
</template>
