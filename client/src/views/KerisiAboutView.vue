<script setup lang="ts">
import { ref } from "vue";
import AdminLayout from "@/layouts/AdminLayout.vue";
import * as BRANDING from "@/config/branding";
import { Bot, Check, Circle, Sparkles, Users } from "lucide-vue-next";

const assetsBase = import.meta.env.BASE_URL;

/** Official photos in `client/public/team/`; fallback if loading fails. */
const teamRosliSrc = ref(`${assetsBase}team/rosli.png`);
const teamRidzuanSrc = ref(`${assetsBase}team/ridzuan.png`);
const teamMasriSrc = ref(`${assetsBase}team/masri.png`);
const rosliFallback =
  "https://ui-avatars.com/api/?name=Rosli+Amir&size=128&background=0d9488&color=fff&bold=true";
const ridzuanFallback =
  "https://ui-avatars.com/api/?name=Ridzuan+Mohamad&size=128&background=0369a1&color=fff&bold=true";
const masriFallback =
  "https://ui-avatars.com/api/?name=Masri+Yakob&size=128&background=4f46e5&color=fff&bold=true";

function onRosliImgError() {
  teamRosliSrc.value = rosliFallback;
}
function onRidzuanImgError() {
  teamRidzuanSrc.value = ridzuanFallback;
}
function onMasriImgError() {
  teamMasriSrc.value = masriFallback;
}

const ROLE_LEVELS = [
  { id: "super_admin", label: "L0 — Super Admin" },
  { id: "internal_admin", label: "L1 — Internal Admin" },
  { id: "external_admin", label: "L2 — External Admin" },
  { id: "agent", label: "L3 — Agent" },
  { id: "user", label: "L4 — User / Requestor" },
];

const hierarchyRules = [
  "L0 Super Admin: full visibility across all branches (including internal/external tickets, Knowledge Base, ticket monitoring, and user data).",
  "L1 Internal Admin: can see L2, L3, and L4 within their own internal hierarchy branch, including direct agent/user reports.",
  "L2 External Admin: can only see Agent (L3) and User (L4) under their own external branch.",
  "L3 Agent: handles assigned/reassigned/self-created tickets in scope; can use SELAR (Support Chat), internal ticket replies with rich editor, AI draft suggestions (when assigned and enabled), and Desk365 ticket logs based on permission. Knowledge Base management (upload/manage) is generally for L0–L2 unless explicitly granted by RBAC.",
  "L4 User / Requestor: creates and manages own support tickets while allowed; interacts via AINA (AI Navigation Agent — User Chat, KB-only); can view Desk365 ticket lists and monitoring views within linked customer/system scope; no SELAR access.",
  "The same scope model generally applies to menu visibility, user data, notifications, ticket list/detail visibility (KEHSA / KERISI), Support Chat session visibility, and AI sync logs — subject to configured RBAC roles.",
];

/** Feature summary for About page (aligned with current code). */
const featureHighlights = [
  "AINA (AI Navigation Agent — User Chat) and SELAR (Support Chat): attachments, history, favorites, shortcut suggestions, and message search",
  "Knowledge Base — upload/manage by module & document type (L0–L2 by default; L3 only when permission is granted)",
  "Desk365 and internal ticket sync into AI (RAG), including sync logs in Administration and Knowledge Base",
  "Ticket statistics (Desk365 and internal) in Knowledge Base and monitoring views",
  "Internal support tickets (KEHSA) — Markdown conversation, @mentions, internal notes, hierarchy-based assignment, status flow",
  "AI suggestions for agent reply drafts (available after assignment; review required before sending)",
  "AINA assistance and satisfaction flow for requestors on tickets (if enabled)",
  "Guide and About pages with role-vs-version access matrix",
];

// Access matrix by role level (L0–L4). RBAC can further restrict access.
const features = [
  {
    name: "AINA (AI Navigation Agent — User Chat)",
    beta01: true,
    super_admin: true,
    internal_admin: true,
    external_admin: true,
    agent: true,
    user: true,
    details: [
      { name: "Solo chat only", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Module filter", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Knowledge-only responses (no SQL / schema)", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "File attachments (PDF, DOCX, Excel, images)", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Chat history & favorites", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Shortcut suggestions", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Copy message", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Search in chat", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
    ],
  },
  {
    name: "SELAR (Support Chat)",
    beta01: true,
    super_admin: true,
    internal_admin: true,
    external_admin: true,
    agent: true,
    user: false,
    details: [
      { name: "Solo and group chat", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: false },
      { name: "Module filter (Cashbook, GL, Payroll, etc.)", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: false },
      { name: "Ticket context support", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: false },
      { name: "File attachments (PDF, DOCX, Excel, images)", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: false },
      { name: "Chat history & favorites", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: false },
      { name: "Shortcut suggestions", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: false },
      { name: "Forward to agent", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: false },
      { name: "Share session", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: false },
    ],
  },
  {
    name: "Knowledge Base",
    beta01: true,
    super_admin: true,
    internal_admin: true,
    external_admin: true,
    agent: false,
    user: false,
    details: [
      { name: "Document upload (PDF, DOCX, Excel, TXT)", super_admin: true, internal_admin: true, external_admin: true, agent: false, user: false },
      { name: "Module tagging", super_admin: true, internal_admin: true, external_admin: true, agent: false, user: false },
      {
        name: "Document type (user manuals, BL, workflows, RBAC, schema, support tickets)",
        super_admin: true,
        internal_admin: true,
        external_admin: true,
        agent: false,
        user: false,
      },
      { name: "Delete document", super_admin: true, internal_admin: true, external_admin: true, agent: false, user: false },
      { name: "Status tracking (success / failed)", super_admin: true, internal_admin: true, external_admin: true, agent: false, user: false },
      { name: "Desk365 ticket sync logs to AI", super_admin: true, internal_admin: true, external_admin: true, agent: false, user: false },
      { name: "Internal ticket sync logs to AI", super_admin: true, internal_admin: true, external_admin: true, agent: false, user: false },
      { name: "Setup and upgrade helpers", super_admin: true, internal_admin: true, external_admin: true, agent: false, user: false },
    ],
  },
  {
    name: "Desk365 ticket logs",
    beta01: true,
    super_admin: true,
    internal_admin: true,
    external_admin: true,
    agent: true,
    user: true,
    details: [
      { name: "Synced ticket list from database", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Customer-based filtering (especially for user level)", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Search (ticket no, subject, contact, agent)", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Expand details", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Copy to clipboard", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Forward to agent", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: false },
      { name: "Open in chat", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: false },
      { name: "Pagination", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
    ],
  },
  {
    name: "Ticket monitoring (dashboard)",
    beta01: true,
    super_admin: true,
    internal_admin: true,
    external_admin: true,
    agent: true,
    user: true,
    details: [
      { name: "Status summary & operational ticket metrics", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      {
        name: "Admin path → Ticket Monitoring (L0 Super Admin)",
        super_admin: true,
        internal_admin: true,
        external_admin: true,
        agent: true,
        user: true,
      },
      {
        name: "KERISI path → Ticket Monitoring (non-L0 roles)",
        super_admin: true,
        internal_admin: true,
        external_admin: true,
        agent: true,
        user: true,
      },
      {
        name: "Access based on knowledge/ticket permissions (RBAC)",
        super_admin: true,
        internal_admin: true,
        external_admin: true,
        agent: true,
        user: true,
      },
      {
        name: "Internal ticket stats (knowledge) & sync logs",
        super_admin: true,
        internal_admin: true,
        external_admin: true,
        agent: true,
        user: true,
      },
      {
        name: "Desk365 + internal ticket data integration (ops context)",
        super_admin: true,
        internal_admin: true,
        external_admin: true,
        agent: true,
        user: true,
      },
      { name: "Role-based filtering & search", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Responsive layout for quick review", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
    ],
  },
  {
    name: "Internal support tickets (KEHSA)",
    beta01: true,
    super_admin: true,
    internal_admin: true,
    external_admin: true,
    agent: true,
    user: true,
    details: [
      { name: "Create ticket (requestor & agent)", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      {
        name: "List & detail by hierarchy scope",
        super_admin: true,
        internal_admin: true,
        external_admin: true,
        agent: true,
        user: true,
      },
      {
        name: "Assign or reassign agent",
        super_admin: true,
        internal_admin: true,
        external_admin: true,
        agent: true,
        user: false,
      },
      {
        name: "Markdown reply, preview & @staff mention",
        super_admin: true,
        internal_admin: true,
        external_admin: true,
        agent: true,
        user: true,
      },
      {
        name: "Internal notes (staff-only)",
        super_admin: true,
        internal_admin: true,
        external_admin: true,
        agent: true,
        user: false,
      },
      {
        name: "AI suggestion for draft reply (agent, after assignment)",
        super_admin: true,
        internal_admin: true,
        external_admin: true,
        agent: true,
        user: false,
      },
      {
        name: "AINA response & satisfaction flow (can be disabled per ticket)",
        super_admin: true,
        internal_admin: true,
        external_admin: true,
        agent: true,
        user: true,
      },
      {
        name: "Quick status actions (resolve / close by rules)",
        super_admin: true,
        internal_admin: true,
        external_admin: true,
        agent: true,
        user: true,
      },
    ],
  },
  {
    name: "Guide",
    beta01: true,
    super_admin: true,
    internal_admin: true,
    external_admin: true,
    agent: true,
    user: true,
    details: [
      { name: "User guide", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Agent guide", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Admin guide", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
    ],
  },
  {
    name: "This page (About)",
    beta01: true,
    super_admin: true,
    internal_admin: true,
    external_admin: true,
    agent: true,
    user: true,
    details: [
      { name: "Feature list", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Version table", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Role access matrix", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
      { name: "Multi-level hierarchy policy (L0–L4)", super_admin: true, internal_admin: true, external_admin: true, agent: true, user: true },
    ],
  },
];

const versions = [
  { id: "beta01", label: "Beta 0.1", date: "2025-03-18", current: true, note: "Initial release: AI chat, Knowledge Base, Desk365 & internal tickets, AI sync, ticket monitoring." },
];

/** Future roadmap placeholder (to be updated when new versions ship). */
const versionRoadmap = [
  { label: "Beta 0.2+", status: "planned", note: "Updates will be listed here (e.g., RAG improvements, reporting, integrations)." },
];

function hasAccess(f: Record<string, unknown>, roleId: string): boolean {
  return (f[roleId] as boolean) === true;
}
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-6xl px-4 py-6 space-y-8">
      <h1 class="flex items-center gap-2 text-xl font-semibold text-gray-900 dark:text-slate-100">
        <Bot class="h-6 w-6 text-sky-600 dark:text-sky-400" />
        About {{ BRANDING.PLATFORM_HEADER }}
      </h1>

      <!-- Introduction -->
      <section
        class="overflow-hidden rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <h2 class="flex items-center gap-2 text-base font-semibold text-gray-900 dark:text-slate-100">
          <Sparkles class="h-5 w-5 text-amber-500" />
          Introduction
        </h2>
        <p class="mt-3 text-sm text-gray-600 dark:text-slate-400 leading-relaxed">
          <strong>{{ BRANDING.PLATFORM_HEADER }}</strong> — <em>{{ BRANDING.PLATFORM_SUBTITLE }}</em> — is an administration suite for
          {{ BRANDING.ERP_SYSTEM_NAME }} (Tourism Malaysia and related agencies).
          <strong>SELAR</strong> supports {{ BRANDING.SUPPORT_CHAT_TAGLINE }} for staff and agents;
          <strong>AINA (AI Navigation Agent)</strong> supports {{ BRANDING.USER_CHAT_TAGLINE }} for end users.
          Core modules include Knowledge Base, external tickets (Desk365), internal tickets, AI sync for RAG, and operational monitoring.
        </p>
        <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 dark:border-amber-900/50 dark:bg-amber-950/30">
          <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">Multi-Level Tree Access (important)</p>
          <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-amber-900 dark:text-amber-200/90">
            <li v-for="rule in hierarchyRules" :key="rule">{{ rule }}</li>
          </ul>
        </div>
      </section>

      <!-- Version -->
      <section
        class="overflow-hidden rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Version</h2>
        <p class="mt-2 text-sm text-gray-600 dark:text-slate-400">
          Development starts at <strong>Beta</strong>. The current version and short roadmap are listed below.
        </p>
        <ol class="mt-4 space-y-4 border-l-2 border-sky-200 pl-4 dark:border-sky-800">
          <li v-for="v in versions" :key="v.id" class="relative">
            <span
              class="absolute -left-[21px] top-1 flex h-3 w-3 rounded-full border-2 border-white bg-sky-600 dark:border-slate-900 dark:bg-sky-400"
              aria-hidden="true"
            />
            <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">
              {{ v.label }}
              <span
                v-if="v.current"
                class="ml-2 rounded bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300"
              >current</span>
            </p>
            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ v.date }}</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-slate-400">{{ v.note }}</p>
          </li>
          <li v-for="(r, idx) in versionRoadmap" :key="idx" class="relative opacity-80">
            <Circle class="absolute -left-[23px] top-1 h-2.5 w-2.5 fill-gray-300 text-gray-400" aria-hidden="true" />
            <p class="text-sm font-medium text-gray-800 dark:text-slate-200">
              {{ r.label }} — <span class="text-gray-500 dark:text-slate-400">{{ r.status }}</span>
            </p>
            <p class="mt-1 text-sm text-gray-600 dark:text-slate-400">{{ r.note }}</p>
          </li>
        </ol>
      </section>

      <!-- Features -->
      <section
        class="overflow-hidden rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Features</h2>
        <p class="mt-2 text-sm text-gray-600 dark:text-slate-400 mb-3">
          Technical summary. The table below separates the <strong>features</strong> column from <strong>user level</strong>
          columns with a vertical divider (same concept as the divider between user level and version).
        </p>
        <ul class="list-disc space-y-2 pl-5 text-sm text-gray-700 dark:text-slate-300">
          <li v-for="item in featureHighlights" :key="item">{{ item }}</li>
        </ul>
      </section>

      <!-- Team -->
      <section
        class="overflow-hidden rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <h2 class="flex items-center gap-2 text-base font-semibold text-gray-900 dark:text-slate-100">
          <Users class="h-5 w-5 text-sky-600" />
          Team
        </h2>
        <p class="mt-2 text-xs text-gray-500 dark:text-slate-400">
          Official team photos; if a file is unavailable, the UI falls back to initials avatar.
        </p>
        <div class="mt-4 flex flex-wrap gap-8">
          <div class="flex items-center gap-4">
            <img
              :src="teamRosliSrc"
              width="128"
              height="128"
              class="h-32 w-32 shrink-0 rounded-xl border border-gray-200 object-cover object-top shadow-sm dark:border-slate-600"
              alt="Rosli Amir"
              loading="lazy"
              @error="onRosliImgError"
            />
            <div>
              <p class="font-semibold text-gray-900 dark:text-slate-100">Rosli Amir</p>
              <p class="text-sm text-teal-700 dark:text-teal-400">VibeCoder</p>
            </div>
          </div>
          <div class="flex items-center gap-4">
            <img
              :src="teamRidzuanSrc"
              width="128"
              height="128"
              class="h-32 w-32 shrink-0 rounded-xl border border-gray-200 object-cover object-center shadow-sm dark:border-slate-600"
              alt="Ridzuan Mohamad"
              loading="lazy"
              @error="onRidzuanImgError"
            />
            <div>
              <p class="font-semibold text-gray-900 dark:text-slate-100">Ridzuan Mohamad</p>
              <p class="text-sm text-sky-700 dark:text-sky-400">Co-VibeCoder</p>
            </div>
          </div>
          <div class="flex items-center gap-4">
            <img
              :src="teamMasriSrc"
              width="128"
              height="128"
              class="h-32 w-32 shrink-0 rounded-xl border border-gray-200 object-cover object-center shadow-sm dark:border-slate-600"
              alt="Masri Yakob"
              loading="lazy"
              @error="onMasriImgError"
            />
            <div>
              <p class="font-semibold text-gray-900 dark:text-slate-100">Masri Yakob</p>
              <p class="text-sm text-violet-700 dark:text-violet-400">Co-VibeCoder Cum SME</p>
            </div>
          </div>
        </div>
      </section>

      <!-- Access matrix by level -->
      <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
        <div class="border-b border-gray-200 p-6 dark:border-slate-700">
          <h2 class="text-base font-semibold text-gray-900 dark:text-slate-100">Access matrix by level</h2>
          <p class="mt-2 text-sm text-gray-600 dark:text-slate-400">
            Access table by role (L0–L4) and Beta 0.1 version. RBAC may further restrict access.
          </p>
        </div>

        <div class="p-6 overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-200 bg-gray-50 dark:bg-slate-800/50">
                <th
                  class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-slate-200 min-w-[180px] border-r-2 border-gray-300 dark:border-slate-500"
                >
                  Features
                </th>
                <th
                  colspan="5"
                  class="text-center py-2 px-3 font-semibold text-gray-600 dark:text-slate-300 border-r-2 border-gray-300 dark:border-slate-500"
                >
                  User level
                </th>
                <th
                  v-for="v in versions"
                  :key="v.id"
                  class="text-center py-2 px-4 font-semibold text-gray-600 dark:text-slate-300"
                >
                  Version
                </th>
              </tr>
              <tr class="border-b border-gray-200 dark:border-slate-700">
                <th
                  class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-slate-200 min-w-[180px] border-r-2 border-gray-300 dark:border-slate-500"
                >
                  Features
                </th>
                <th
                  v-for="r in ROLE_LEVELS"
                  :key="r.id"
                  class="text-center py-3 px-3 font-semibold text-gray-700 dark:text-slate-200"
                  :class="r.id === 'user' ? 'border-r-2 border-gray-300 dark:border-slate-500' : ''"
                >
                  {{ r.label }}
                </th>
                <th
                  v-for="v in versions"
                  :key="v.id"
                  class="text-center py-3 px-4 font-semibold text-gray-700 dark:text-slate-200"
                >
                  <span class="flex items-center justify-center gap-1.5">
                    {{ v.label }}
                    <span
                      v-if="v.current"
                      class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-blue-600 text-white"
                      title="Current version"
                    >
                      <Check class="w-3 h-3" />
                    </span>
                  </span>
                  <p class="text-xs font-normal text-gray-500 dark:text-slate-400 mt-0.5">{{ v.date }}</p>
                </th>
              </tr>
            </thead>
            <tbody>
              <template v-for="f in features" :key="f.name">
                <tr class="border-b border-gray-100 hover:bg-gray-50 dark:border-slate-700 dark:hover:bg-slate-800/40">
                  <td
                    class="py-3 px-4 text-gray-800 dark:text-slate-100 font-medium border-r-2 border-gray-300 dark:border-slate-500"
                  >
                    {{ f.name }}
                  </td>
                  <td
                    v-for="r in ROLE_LEVELS"
                    :key="r.id"
                    class="text-center py-3 px-3"
                    :class="r.id === 'user' ? 'border-r-2 border-gray-300 dark:border-slate-500' : ''"
                  >
                    <Check v-if="hasAccess(f, r.id)" class="w-4 h-4 inline text-green-600 dark:text-green-400" />
                    <span v-else class="text-gray-300">—</span>
                  </td>
                  <td
                    v-for="v in versions"
                    :key="v.id"
                    class="text-center py-3 px-4"
                  >
                    <Check v-if="(f as Record<string, unknown>)[v.id] === true" class="w-4 h-4 inline text-green-600" />
                    <span v-else class="text-gray-300">—</span>
                  </td>
                </tr>
                <tr
                  v-for="d in f.details"
                  :key="`${f.name}-${d.name}`"
                  class="border-b border-gray-50 bg-gray-50/50 hover:bg-gray-50 dark:border-slate-800/80 dark:bg-slate-900/40 dark:hover:bg-slate-800/50"
                >
                  <td
                    class="py-2 px-4 pl-8 text-gray-600 dark:text-slate-300 text-xs border-r-2 border-gray-300 dark:border-slate-500"
                  >
                    {{ d.name }}
                  </td>
                  <td
                    v-for="r in ROLE_LEVELS"
                    :key="r.id"
                    class="text-center py-2 px-3"
                    :class="r.id === 'user' ? 'border-r-2 border-gray-300 dark:border-slate-500' : ''"
                  >
                    <Check v-if="hasAccess(d, r.id)" class="w-3.5 h-3.5 inline text-green-500 dark:text-green-400" />
                    <span v-else class="text-gray-300">—</span>
                  </td>
                  <td
                    v-for="v in versions"
                    :key="v.id"
                    class="text-center py-2 px-4"
                  >
                    <Check v-if="(f as Record<string, unknown>)[v.id] === true" class="w-3.5 h-3.5 inline text-green-500" />
                    <span v-else class="text-gray-300">—</span>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </AdminLayout>
</template>
