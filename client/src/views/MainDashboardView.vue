<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import {
  ArrowRight,
  BarChart3,
  Bell,
  ChartNoAxesCombined,
  FileText,
  Image,
  LayoutGrid,
  MessageSquare,
  RefreshCw,
  Settings,
  Shield,
  Ticket,
  Users,
} from "lucide-vue-next";

import AdminLayout from "@/layouts/AdminLayout.vue";
import { fetchDashboardSummary, fetchDashboardAnalytics, type DashboardAnalytics } from "@/api/cms";
import type { UserLevel } from "@/types";
import { STAFF_USER_LEVELS } from "@/types";

const router = useRouter();
const summary = ref<{
  userLevel: UserLevel;
  counts: { posts: number; pages: number; media: number; users: number };
  support?: { ticketCount: number | null; desk365TicketCount?: number | null; internalTicketCount?: number | null };
  unreadNotifications?: number;
  postsByYear?: { year: number; count: number }[];
} | null>(null);
const analytics = ref<DashboardAnalytics | null>(null);
const loading = ref(true);
const analyticsLoading = ref(true);

onMounted(async () => {
  try {
    const [summaryRes, analyticsRes] = await Promise.all([
      fetchDashboardSummary(),
      fetchDashboardAnalytics(),
    ]);
    summary.value = summaryRes.data;
    analytics.value = analyticsRes.data;
  } catch {
    // summary may have loaded
  } finally {
    loading.value = false;
    analyticsLoading.value = false;
  }
});

const counts = computed(() => summary.value?.counts ?? { posts: 0, pages: 0, media: 0, users: 0 });
const userLevel = computed(() => (summary.value?.userLevel ?? "user") as UserLevel);
const ticketCount = computed(() => summary.value?.support?.ticketCount ?? null);
const desk365TicketCount = computed(() => summary.value?.support?.desk365TicketCount ?? null);
const internalTicketCount = computed(() => summary.value?.support?.internalTicketCount ?? null);
const unreadNotifCount = computed(() => summary.value?.unreadNotifications ?? 0);

const isSuperAdmin = computed(() => userLevel.value === "super_admin");
/** Level 1 or 2 — pentadbir dalaman / luaran (shared support admin dashboard). */
const isPentadbirDashboard = computed(
  () => userLevel.value === "internal_admin" || userLevel.value === "external_admin",
);
const pentadbirTierLabel = computed(() =>
  userLevel.value === "internal_admin" ? "Level 1" : userLevel.value === "external_admin" ? "Level 2" : "",
);
const isAgent = computed(() => userLevel.value === "agent");
const isSupportRole = computed(() => STAFF_USER_LEVELS.includes(userLevel.value));
const endUserTierLabel = computed(() => (userLevel.value === "secondary_user" ? "Level 5" : "Level 4"));

function toBarData(record: Record<string, number>, maxBars = 10): { label: string; value: number }[] {
  return Object.entries(record)
    .map(([k, v]) => ({ label: k || "—", value: v }))
    .sort((a, b) => b.value - a.value)
    .slice(0, maxBars);
}

const ticketsByAgentBars = computed(() => toBarData(analytics.value?.ticketsByAgent ?? {}));
const ticketsByModuleBars = computed(() => toBarData(analytics.value?.ticketsByModule ?? {}));
const internalTicketsByAgentBars = computed(() => toBarData(analytics.value?.internalTicketsByAgent ?? {}));
const internalTicketsByModuleBars = computed(() => toBarData(analytics.value?.internalTicketsByModule ?? {}));
const maxAgentVal = computed(() => Math.max(1, ...ticketsByAgentBars.value.map((x) => x.value)));
const maxModuleVal = computed(() => Math.max(1, ...ticketsByModuleBars.value.map((x) => x.value)));
const maxInternalAgentVal = computed(() => Math.max(1, ...internalTicketsByAgentBars.value.map((x) => x.value)));
const maxInternalModuleVal = computed(() => Math.max(1, ...internalTicketsByModuleBars.value.map((x) => x.value)));

const postsByYearRows = computed(() => summary.value?.postsByYear ?? []);
const maxPostsYearCount = computed(() => Math.max(1, ...postsByYearRows.value.map((r) => r.count)));
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-7xl space-y-5">
      <!-- Super Admin: Platform Control Center -->
      <div
        v-if="isSuperAdmin"
        class="rounded-2xl border border-slate-200 bg-gradient-to-r from-slate-50 to-white p-6 shadow-sm"
      >
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Level 0</p>
        <h1 class="mt-2 text-2xl font-semibold text-slate-900">Platform Control Center</h1>
        <p class="mt-1 text-sm text-slate-500">Full platform overview. Support, content, identity, and system.</p>
        <div class="mt-4 flex flex-wrap gap-2">
          <button
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
            @click="router.push('/admin/kerisi/chat')"
          >
            <MessageSquare class="h-4 w-4" /> Support Chat
          </button>
          <button
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
            @click="router.push('/admin/platform/desk365')"
          >
            <RefreshCw class="h-4 w-4" /> Desk365 log
          </button>
          <button
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
            @click="router.push('/admin/platform/ticket-log')"
          >
            <Ticket class="h-4 w-4" /> Ticket log
          </button>
          <button
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
            @click="router.push('/admin/tickets/monitoring')"
          >
            <BarChart3 class="h-4 w-4" /> Ticket monitoring
          </button>
          <button
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
            @click="router.push('/admin/platform/identity/users')"
          >
            <Shield class="h-4 w-4" /> Identity & Access
          </button>
          <button
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
            @click="router.push('/admin/portal/dashboard')"
          >
            <ArrowRight class="h-4 w-4" /> Webfront Dashboard
          </button>
        </div>
      </div>

      <!-- Pentadbir (L1 internal / L2 external): Support Admin Dashboard -->
      <div
        v-else-if="isPentadbirDashboard"
        class="rounded-2xl border border-slate-200 bg-gradient-to-r from-slate-50 to-white p-6 shadow-sm"
      >
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ pentadbirTierLabel }}</p>
        <h1 class="mt-2 text-2xl font-semibold text-slate-900">Support Admin Dashboard</h1>
        <p class="mt-1 text-sm text-slate-500">Manage support operations, tickets, and users.</p>
        <div class="mt-4 flex flex-wrap gap-2">
          <button
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
            @click="router.push('/admin/kerisi/chat')"
          >
            <MessageSquare class="h-4 w-4" /> Support Chat
          </button>
          <button
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
            @click="router.push('/admin/platform/desk365')"
          >
            <RefreshCw class="h-4 w-4" /> Desk365 log
          </button>
          <button
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
            @click="router.push('/admin/platform/ticket-log')"
          >
            <Ticket class="h-4 w-4" /> Ticket log
          </button>
          <button
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
            @click="router.push('/admin/tickets/monitoring')"
          >
            <BarChart3 class="h-4 w-4" /> Ticket monitoring
          </button>
          <button
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
            @click="router.push('/admin/platform/identity/users')"
          >
            <Shield class="h-4 w-4" /> Identity & Access
          </button>
          <button
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
            @click="router.push('/admin/settings')"
          >
            <Settings class="h-4 w-4" /> Settings
          </button>
        </div>
      </div>

      <!-- Agent: Support Agent Dashboard -->
      <div
        v-else-if="isAgent"
        class="rounded-2xl border border-slate-200 bg-gradient-to-r from-slate-50 to-white p-6 shadow-sm"
      >
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Level 3 — Ejen</p>
        <h1 class="mt-2 text-2xl font-semibold text-slate-900">Support Agent Dashboard</h1>
        <p class="mt-1 text-sm text-slate-500">Your assigned tickets and Support Chat.</p>
        <div class="mt-4 flex flex-wrap gap-2">
          <button
            class="inline-flex items-center gap-1.5 rounded-lg border border-violet-300 bg-violet-50 px-3 py-2 text-sm font-medium text-violet-800 transition-colors hover:bg-violet-100"
            @click="router.push('/admin/kerisi/chat')"
          >
            <MessageSquare class="h-4 w-4" /> Open Support Chat
          </button>
          <button
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
            @click="router.push('/admin/kerisi/knowledge')"
          >
            Knowledge Base
          </button>
        </div>
      </div>

      <!-- Fallback: Level 4–5 — pengguna / pemohon -->
      <div
        v-else
        class="rounded-2xl border border-slate-200 bg-gradient-to-r from-slate-50 to-white p-6 shadow-sm"
      >
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ endUserTierLabel }}</p>
        <h1 class="mt-2 text-2xl font-semibold text-slate-900">Control Center</h1>
        <p class="mt-1 text-sm text-slate-500">View your own ticket stats and unread notifications.</p>
        <button
          class="mt-4 inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
          @click="router.push('/admin/portal/dashboard')"
        >
          Open Dashboard
          <ArrowRight class="h-4 w-4" />
        </button>
      </div>

      <!-- Stats grid: different per level -->
      <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
        <article
          class="cursor-pointer rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition-colors hover:border-violet-300"
          @click="router.push('/admin/kerisi/notifications')"
        >
          <div class="flex items-center justify-between">
            <p class="text-xs font-medium text-slate-500">Unread notifications</p>
            <Bell class="h-4 w-4 text-slate-400" />
          </div>
          <p class="mt-2 text-2xl font-semibold text-slate-900">{{ loading ? "-" : unreadNotifCount }}</p>
        </article>
        <article
          v-if="!isSupportRole"
          class="cursor-pointer rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition-colors hover:border-violet-300"
          @click="router.push('/admin/platform/desk365')"
        >
          <div class="flex items-center justify-between">
            <p class="text-xs font-medium text-slate-500">My Desk365 Tickets</p>
            <Ticket class="h-4 w-4 text-slate-400" />
          </div>
          <p class="mt-2 text-2xl font-semibold text-slate-900">{{ loading ? "-" : (desk365TicketCount ?? 0) }}</p>
        </article>
        <article
          v-if="!isSupportRole"
          class="cursor-pointer rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition-colors hover:border-violet-300"
          @click="router.push('/admin/kerisi/ticket')"
        >
          <div class="flex items-center justify-between">
            <p class="text-xs font-medium text-slate-500">My Internal Tickets</p>
            <MessageSquare class="h-4 w-4 text-slate-400" />
          </div>
          <p class="mt-2 text-2xl font-semibold text-slate-900">{{ loading ? "-" : (internalTicketCount ?? 0) }}</p>
        </article>
        <article
          v-if="isSuperAdmin || isPentadbirDashboard"
          class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
        >
          <div class="flex items-center justify-between">
            <p class="text-xs font-medium text-slate-500">Users</p>
            <Users class="h-4 w-4 text-slate-400" />
          </div>
          <p class="mt-2 text-2xl font-semibold text-slate-900">{{ loading ? "-" : counts.users }}</p>
        </article>
        <article
          v-if="ticketCount !== null && isSupportRole"
          class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm cursor-pointer hover:border-violet-300 transition-colors"
          @click="router.push('/admin/kerisi/chat')"
        >
          <div class="flex items-center justify-between">
            <p class="text-xs font-medium text-slate-500">{{ isAgent ? "My Tickets" : "Open Tickets" }}</p>
            <MessageSquare class="h-4 w-4 text-slate-400" />
          </div>
          <p class="mt-2 text-2xl font-semibold text-slate-900">{{ loading ? "-" : ticketCount }}</p>
        </article>
        <article
          v-if="isSuperAdmin || isPentadbirDashboard"
          class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
        >
          <div class="flex items-center justify-between">
            <p class="text-xs font-medium text-slate-500">Posts</p>
            <FileText class="h-4 w-4 text-slate-400" />
          </div>
          <p class="mt-2 text-2xl font-semibold text-slate-900">{{ loading ? "-" : counts.posts }}</p>
        </article>
        <article
          v-if="isSuperAdmin || isPentadbirDashboard"
          class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
        >
          <div class="flex items-center justify-between">
            <p class="text-xs font-medium text-slate-500">Pages</p>
            <LayoutGrid class="h-4 w-4 text-slate-400" />
          </div>
          <p class="mt-2 text-2xl font-semibold text-slate-900">{{ loading ? "-" : counts.pages }}</p>
        </article>
        <article
          v-if="isSuperAdmin"
          class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
        >
          <div class="flex items-center justify-between">
            <p class="text-xs font-medium text-slate-500">Media</p>
            <Image class="h-4 w-4 text-slate-400" />
          </div>
          <p class="mt-2 text-2xl font-semibold text-slate-900">{{ loading ? "-" : counts.media }}</p>
        </article>
      </div>

      <!-- Jumlah pos (published) mengikut tahun -->
      <section
        v-if="isSuperAdmin || isPentadbirDashboard"
        class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
      >
        <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-800">
          <ChartNoAxesCombined class="h-4 w-4 text-emerald-600" /> Jumlah pos mengikut tahun
        </h2>
        <p class="mt-1 text-xs text-slate-500">
          Bilangan siaran webfront <span class="font-medium">published</span> mengikut tahun tarikh
          <span class="font-mono text-[11px] text-slate-600">published_at</span>.
        </p>
        <div v-if="loading" class="mt-3 h-24 animate-pulse rounded bg-slate-100" />
        <ul v-else-if="postsByYearRows.length" class="mt-3 max-h-56 space-y-2 overflow-y-auto">
          <li v-for="row in postsByYearRows" :key="row.year" class="flex items-center gap-2 text-sm">
            <span class="w-14 shrink-0 font-mono text-slate-600">{{ row.year }}</span>
            <div class="min-w-0 flex-1">
              <div
                class="h-4 min-w-[4px] rounded bg-emerald-200"
                :style="{ width: `${(row.count / maxPostsYearCount) * 100}%` }"
              />
            </div>
            <span class="w-10 shrink-0 text-right font-medium text-slate-800">{{ row.count }}</span>
          </li>
        </ul>
        <p v-else class="mt-3 text-xs text-slate-500">Tiada pos published dengan tarikh.</p>
      </section>

      <!-- Admin: Tickets by Agent (graph) -->
      <section v-if="(isSuperAdmin || isPentadbirDashboard) && (analyticsLoading || ticketsByAgentBars.length)" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-800">
          <BarChart3 class="h-4 w-4" /> Open Tickets by Agent
        </h2>
        <div v-if="analyticsLoading" class="mt-3 h-32 animate-pulse rounded bg-slate-100" />
        <div v-else-if="ticketsByAgentBars.length" class="mt-3 space-y-2">
          <div
            v-for="bar in ticketsByAgentBars"
            :key="bar.label"
            class="flex items-center gap-2"
          >
            <span class="w-32 truncate text-xs text-slate-600">{{ bar.label }}</span>
            <div class="min-w-0 flex-1">
              <div
                class="h-5 rounded bg-violet-200"
                :style="{ width: `${(bar.value / maxAgentVal) * 100}%` }"
              />
            </div>
            <span class="w-6 text-right text-xs font-medium text-slate-700">{{ bar.value }}</span>
          </div>
        </div>
        <p v-else class="mt-3 text-xs text-slate-500">No open tickets.</p>
      </section>

      <!-- Admin: Tickets by Module (graph) -->
      <section v-if="(isSuperAdmin || isPentadbirDashboard) && (analyticsLoading || ticketsByModuleBars.length)" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-800">
          <BarChart3 class="h-4 w-4" /> Open Tickets by Module
        </h2>
        <div v-if="analyticsLoading" class="mt-3 h-32 animate-pulse rounded bg-slate-100" />
        <div v-else-if="ticketsByModuleBars.length" class="mt-3 space-y-2">
          <div
            v-for="bar in ticketsByModuleBars"
            :key="bar.label"
            class="flex items-center gap-2"
          >
            <span class="w-32 truncate text-xs text-slate-600">{{ bar.label }}</span>
            <div class="min-w-0 flex-1">
              <div
                class="h-5 rounded bg-slate-200"
                :style="{ width: `${(bar.value / maxModuleVal) * 100}%` }"
              />
            </div>
            <span class="w-6 text-right text-xs font-medium text-slate-700">{{ bar.value }}</span>
          </div>
        </div>
        <p v-else class="mt-3 text-xs text-slate-500">No open tickets.</p>
      </section>

      <!-- Admin: Internal Tickets by Agent (graph) -->
      <section v-if="(isSuperAdmin || isPentadbirDashboard) && (analyticsLoading || internalTicketsByAgentBars.length)" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-800">
          <BarChart3 class="h-4 w-4" /> Open Internal Tickets by Agent
        </h2>
        <div v-if="analyticsLoading" class="mt-3 h-32 animate-pulse rounded bg-slate-100" />
        <div v-else-if="internalTicketsByAgentBars.length" class="mt-3 space-y-2">
          <div
            v-for="bar in internalTicketsByAgentBars"
            :key="bar.label"
            class="flex items-center gap-2"
          >
            <span class="w-32 truncate text-xs text-slate-600">{{ bar.label }}</span>
            <div class="min-w-0 flex-1">
              <div
                class="h-5 rounded bg-emerald-200"
                :style="{ width: `${(bar.value / maxInternalAgentVal) * 100}%` }"
              />
            </div>
            <span class="w-6 text-right text-xs font-medium text-slate-700">{{ bar.value }}</span>
          </div>
        </div>
        <p v-else class="mt-3 text-xs text-slate-500">No open internal tickets.</p>
      </section>

      <!-- Admin: Internal Tickets by Module (graph) -->
      <section v-if="(isSuperAdmin || isPentadbirDashboard) && (analyticsLoading || internalTicketsByModuleBars.length)" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-800">
          <BarChart3 class="h-4 w-4" /> Open Internal Tickets by Module
        </h2>
        <div v-if="analyticsLoading" class="mt-3 h-32 animate-pulse rounded bg-slate-100" />
        <div v-else-if="internalTicketsByModuleBars.length" class="mt-3 space-y-2">
          <div
            v-for="bar in internalTicketsByModuleBars"
            :key="bar.label"
            class="flex items-center gap-2"
          >
            <span class="w-32 truncate text-xs text-slate-600">{{ bar.label }}</span>
            <div class="min-w-0 flex-1">
              <div
                class="h-5 rounded bg-emerald-100"
                :style="{ width: `${(bar.value / maxInternalModuleVal) * 100}%` }"
              />
            </div>
            <span class="w-6 text-right text-xs font-medium text-slate-700">{{ bar.value }}</span>
          </div>
        </div>
        <p v-else class="mt-3 text-xs text-slate-500">No open internal tickets.</p>
      </section>

      <!-- Admin: Top Agents & Chat Sessions -->
      <div v-if="(isSuperAdmin || isPentadbirDashboard) && analytics" class="grid gap-4 md:grid-cols-2">
        <section v-if="analytics.topAgents?.length" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <h2 class="text-sm font-semibold text-slate-800">Top Agents (Open Tickets)</h2>
          <ul class="mt-2 space-y-1 text-xs">
            <li v-for="(a, i) in analytics.topAgents" :key="a.agent" class="flex justify-between">
              <span>{{ i + 1 }}. {{ a.agent }}</span>
              <span class="font-medium">{{ a.count }}</span>
            </li>
          </ul>
        </section>
        <section v-if="analytics.chatSessionsByUser?.length" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <h2 class="text-sm font-semibold text-slate-800">Chat Sessions by User</h2>
          <ul class="mt-2 space-y-1 text-xs">
            <li v-for="(u, i) in analytics.chatSessionsByUser" :key="u.user" class="flex justify-between">
              <span>{{ i + 1 }}. {{ u.user }}</span>
              <span class="font-medium">{{ u.count }}</span>
            </li>
          </ul>
        </section>
      </div>

      <!-- Agent: New Tickets List -->
      <section v-if="isAgent && analytics?.newTickets?.length" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-800">
          <Ticket class="h-4 w-4" /> New Tickets (Assigned to You)
        </h2>
        <ul class="mt-3 space-y-2">
          <li
            v-for="t in analytics.newTickets"
            :key="String(t['Ticket Number'] ?? t)"
            class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-100 p-2 text-sm transition-colors hover:bg-slate-50"
            @click="router.push('/admin/kerisi/chat')"
          >
            <span class="truncate font-medium text-slate-800">{{ t.Subject ?? t.subject ?? "—" }}</span>
            <span class="ml-2 shrink-0 text-xs text-slate-500">{{ t['Ticket Number'] ?? t.ticket_number ?? "" }}</span>
          </li>
        </ul>
        <button
          class="mt-3 w-full rounded-lg border border-violet-200 bg-violet-50 py-2 text-sm font-medium text-violet-800 hover:bg-violet-100"
          @click="router.push('/admin/kerisi/chat')"
        >
          Open Support Chat
        </button>
      </section>

      <!-- Quick actions: Super Admin & Admin -->
      <div v-if="isSuperAdmin || isPentadbirDashboard" class="grid gap-3 md:grid-cols-3">
        <button
          class="rounded-lg border border-slate-200 bg-white p-4 text-left shadow-sm transition-colors hover:bg-slate-50"
          @click="router.push('/admin/kerisi/chat')"
        >
          <div class="flex items-center gap-2 text-sm font-semibold text-slate-800">
            <MessageSquare class="h-4 w-4" /> Support Chat
          </div>
          <p class="mt-1 text-xs text-slate-500">Handle tickets and group chats with AI.</p>
        </button>
        <button
          class="rounded-lg border border-slate-200 bg-white p-4 text-left shadow-sm transition-colors hover:bg-slate-50"
          @click="router.push('/admin/portal/dashboard')"
        >
          <div class="flex items-center gap-2 text-sm font-semibold text-slate-800">
            <ChartNoAxesCombined class="h-4 w-4" /> Webfront Dashboard
          </div>
          <p class="mt-1 text-xs text-slate-500">Content activity and recent updates.</p>
        </button>
        <button
          class="rounded-lg border border-slate-200 bg-white p-4 text-left shadow-sm transition-colors hover:bg-slate-50"
          @click="router.push('/admin/settings')"
        >
          <div class="flex items-center gap-2 text-sm font-semibold text-slate-800">
            <Settings class="h-4 w-4" /> Site Settings
          </div>
          <p class="mt-1 text-xs text-slate-500">Branding, title format, system options.</p>
        </button>
      </div>

      <!-- Agent: single primary CTA -->
      <div v-else-if="isAgent" class="grid gap-3 md:grid-cols-1">
        <button
          class="rounded-lg border-2 border-violet-200 bg-violet-50/50 p-6 text-left shadow-sm transition-colors hover:bg-violet-50 hover:border-violet-300"
          @click="router.push('/admin/kerisi/chat')"
        >
          <div class="flex items-center gap-3 text-lg font-semibold text-violet-900">
            <MessageSquare class="h-6 w-6" /> Open Support Chat
          </div>
          <p class="mt-2 text-sm text-slate-600">
            Start or continue support conversations. View tickets assigned to you and collaborate in group chats.
          </p>
        </button>
      </div>
    </div>
  </AdminLayout>
</template>
