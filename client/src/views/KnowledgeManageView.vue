<script setup lang="ts">
import { ref, onMounted, computed } from "vue";
import { useRouter } from "vue-router";
import AdminLayout from "@/layouts/AdminLayout.vue";
import * as BRANDING from "@/config/branding";
import { useToast } from "@/composables/useToast";
import { useConfirmDialog } from "@/composables/useConfirmDialog";
import {
  listKnowledgeDocs,
  uploadKnowledgeDoc,
  deleteKnowledgeDoc,
  setupKerisiAI,
  upgradeKerisiAssistant,
  setupUserChatAssistant,
  getDbStatus,
  getDesk365Status,
  getDesk365Tickets,
  syncDesk365Tickets,
  getInternalTicketsPreview,
  syncInternalTickets,
  syncKnowledgeSchema,
  syncKnowledgeLookup,
  syncKnowledgeMenuAccess,
  syncKnowledgePages,
  syncKnowledgeBl,
  listKnowledgeExtractSyncLogs,
  listDesk365SyncLogs,
  listInternalTicketSyncLogs,
} from "@/api/cms";

const KNOWLEDGE_MODULES = [
  "Cashbook", "Account Receivable", "Account Payable",
  "General Ledger", "Payroll", "Purchasing", "Vendor Portal",
  "Debtor Portal", "Credit Control", "Investment", "Loan",
  "Asset", "Budget", "Staff Portal", "Student Finance", "Setup & Maintenance",
];
import type { KnowledgeDocument, Desk365Ticket } from "@/types";

const router = useRouter();
import {
  Upload, Trash2, FileText, CheckCircle, XCircle, Clock,
  Loader2, Settings, RefreshCw, BookOpen, Database, Zap,
  BarChart2, Ticket, Code2, Map, BookMarked, FileSearch, GitBranch, Shield,
  MessageSquare,
} from "lucide-vue-next";

const toast = useToast();
const { confirm } = useConfirmDialog();

const docs = ref<KnowledgeDocument[]>([]);
const statsDocs = ref<KnowledgeDocument[]>([]);
const modules = ref<string[]>([]);
const isLoading = ref(false);
const isUploading = ref(false);
const isSettingUp = ref(false);
const isUpgrading = ref(false);
const isSettingUpUserChat = ref(false);
const userChatSetupResult = ref<{ assistant_id: string; message: string } | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const selectedModule = ref("");
const filterModule = ref("");
const searchQ = ref("");
const setupResult = ref<{ vector_store_id: string; assistant_id: string; message: string } | null>(null);
const dbStatus = ref<{ connected: boolean; host: string; database: string } | null>(null);
const dbStatusLoading = ref(false);
const desk365Status = ref<{ configured: boolean; connected?: boolean; base_url?: string; message?: string } | null>(null);
const desk365Tickets = ref<Desk365Ticket[]>([]);
const desk365Loading = ref(false);
const desk365Syncing = ref(false);
const internalTickets = ref<Desk365Ticket[]>([]);
const internalLoading = ref(false);
const internalSyncing = ref(false);
const schemaSyncing = ref(false);
const lookupSyncing = ref(false);
const menuAccessSyncing = ref(false);
const pagesSyncing = ref(false);
const blSyncing = ref(false);
const syncingSelected = ref(false);
const syncTargets = ["schema", "lookup", "menu_access", "pages", "bl", "desk365", "internal"] as const;
const selectedSyncTargets = ref<Array<(typeof syncTargets)[number]>>([...syncTargets]);

const docsPage = ref(1);
const docsLimit = ref(20);
const docsTotal = ref(0);
const docsTotalPages = ref(1);
const lastSyncBySection = ref<Record<string, string | null>>({
  schema: null,
  lookup: null,
  menu_access: null,
  pages: null,
  bl: null,
  desk365: null,
  internal: null,
});

let searchDebounceId: ReturnType<typeof setTimeout> | null = null;

onMounted(async () => {
  modules.value = KNOWLEDGE_MODULES;
  await Promise.all([loadDocs(), loadStatsDocs(), loadLatestTickets(), loadLastSyncs()]);
  // DB status: use Refresh only (tunnel can take 10+ seconds).
});

/** UI list = latest preview; sync API = all Desk365 ticket pages → Vector Store. */
const desk365CanSync = computed(() => desk365Status.value?.configured === true);
const allSyncSelected = computed({
  get: () => selectedSyncTargets.value.length === syncTargets.length,
  set: (v: boolean) => {
    selectedSyncTargets.value = v ? [...syncTargets] : [];
  },
});

async function loadInternalPreview() {
  internalLoading.value = true;
  try {
    const res = await getInternalTicketsPreview(30);
    internalTickets.value = res.data ?? [];
  } catch {
    internalTickets.value = [];
    toast.error("Could not load internal tickets preview");
  } finally {
    internalLoading.value = false;
  }
}

async function handleSyncInternalToAI() {
  internalSyncing.value = true;
  try {
    const res = await syncInternalTickets();
    const d = res.data as any;
    if (d?.success) {
      const extra = d.totalTickets != null ? ` (${d.totalTickets} tickets)` : "";
      toast.success((d.message || "Internal tickets synced to AI") + extra);
      await Promise.all([loadDocs(), loadStatsDocs()]);
      await loadLastSyncs();
    } else {
      toast.error(d?.message || "Sync failed");
    }
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Sync failed");
  } finally {
    internalSyncing.value = false;
  }
}

async function handleSyncSchemaToAI() {
  schemaSyncing.value = true;
  try {
    const res = await syncKnowledgeSchema();
    const d = res.data as any;
    if (d?.success) {
      toast.success(d.message || "Database schema synced to AI");
      await Promise.all([loadDocs(), loadStatsDocs()]);
      await loadLastSyncs();
    } else {
      toast.error(d?.message || "Schema sync failed");
    }
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Schema sync failed");
  } finally {
    schemaSyncing.value = false;
  }
}

async function handleSyncLookupToAI() {
  lookupSyncing.value = true;
  try {
    const res = await syncKnowledgeLookup();
    const d = res.data as any;
    if (d?.success) {
      toast.success(d.message || "Lookup / reference data synced to AI");
      await Promise.all([loadDocs(), loadStatsDocs()]);
      await loadLastSyncs();
    } else {
      toast.error(d?.message || "Lookup sync failed");
    }
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Lookup sync failed");
  } finally {
    lookupSyncing.value = false;
  }
}

async function handleSyncMenuAccessToAI() {
  menuAccessSyncing.value = true;
  try {
    const res = await syncKnowledgeMenuAccess();
    const d = res.data as any;
    if (d?.success) {
      toast.success(d.message || "Menu & access synced to AI");
      await Promise.all([loadDocs(), loadStatsDocs()]);
      await loadLastSyncs();
    } else {
      toast.error(d?.message || "Menu access sync failed");
    }
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Menu access sync failed");
  } finally {
    menuAccessSyncing.value = false;
  }
}

async function handleSyncPagesToAI() {
  pagesSyncing.value = true;
  try {
    const res = await syncKnowledgePages();
    const d = res.data as any;
    if (d?.success) {
      toast.success(d.message || "Page structure synced to AI");
      await Promise.all([loadDocs(), loadStatsDocs()]);
      await loadLastSyncs();
    } else {
      toast.error(d?.message || "Page structure sync failed");
    }
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Page structure sync failed");
  } finally {
    pagesSyncing.value = false;
  }
}

async function handleSyncBlToAI() {
  blSyncing.value = true;
  try {
    const res = await syncKnowledgeBl();
    const d = res.data as any;
    if (d?.success) {
      toast.success(d.message || "Business logic synced to AI");
      await Promise.all([loadDocs(), loadStatsDocs()]);
      await loadLastSyncs();
    } else {
      toast.error(d?.message || "Business logic sync failed");
    }
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Business logic sync failed");
  } finally {
    blSyncing.value = false;
  }
}

async function handleSyncSelectedToAI() {
  if (selectedSyncTargets.value.length === 0 || syncingSelected.value) return;
  syncingSelected.value = true;
  try {
    for (const target of selectedSyncTargets.value) {
      if (target === "schema") {
        await handleSyncSchemaToAI();
      } else if (target === "lookup") {
        await handleSyncLookupToAI();
      } else if (target === "menu_access") {
        await handleSyncMenuAccessToAI();
      } else if (target === "pages") {
        await handleSyncPagesToAI();
      } else if (target === "bl") {
        await handleSyncBlToAI();
      } else if (target === "desk365") {
        await handleSyncDesk365ToAI();
      } else if (target === "internal") {
        await handleSyncInternalToAI();
      }
    }
  } finally {
    syncingSelected.value = false;
  }
}

async function loadDocs() {
  isLoading.value = true;
  try {
    const params = new URLSearchParams();
    if (searchQ.value) params.set("q", searchQ.value);
    if (filterModule.value) params.set("module", filterModule.value);
    params.set("page", String(docsPage.value));
    params.set("limit", String(docsLimit.value));
    const res = await listKnowledgeDocs(`?${params}`);
    docs.value = res.data ?? [];
    const meta = (res as any).meta ?? {};
    docsTotal.value = Number(meta.total ?? docs.value.length);
    docsTotalPages.value = Math.max(1, Number(meta.totalPages ?? 1));
  } catch {
    docs.value = [];
    docsTotal.value = 0;
    docsTotalPages.value = 1;
    toast.error("Could not load document list");
  } finally {
    isLoading.value = false;
  }
}

async function loadStatsDocs() {
  try {
    const res = await listKnowledgeDocs("?page=1&limit=500", { timeoutMs: 120_000 });
    statsDocs.value = res.data ?? [];
  } catch {
    statsDocs.value = [];
  }
}

function onSearchInput() {
  if (searchDebounceId) clearTimeout(searchDebounceId);
  searchDebounceId = setTimeout(() => {
    docsPage.value = 1;
    loadDocs();
  }, 350);
}

function onFilterModuleChange() {
  docsPage.value = 1;
  loadDocs();
}

function prevDocsPage() {
  if (docsPage.value <= 1 || isLoading.value) return;
  docsPage.value -= 1;
  loadDocs();
}

function nextDocsPage() {
  if (docsPage.value >= docsTotalPages.value || isLoading.value) return;
  docsPage.value += 1;
  loadDocs();
}

function triggerUpload() {
  fileInput.value?.click();
}

async function handleFileChange(e: Event) {
  const target = e.target as HTMLInputElement;
  const files = target.files;
  if (!files || files.length === 0) return;
  isUploading.value = true;
  let successCount = 0;
  let failCount = 0;
  for (const file of Array.from(files)) {
    try {
      const formData = new FormData();
      formData.append("file", file);
      if (selectedModule.value) formData.append("module", selectedModule.value);
      await uploadKnowledgeDoc(formData);
      successCount++;
    } catch {
      failCount++;
    }
  }
  isUploading.value = false;
  if (successCount > 0) toast.success(`${successCount} file(s) uploaded to the AI knowledge base (${BRANDING.PLATFORM_HEADER} — SELAR & AINA)`);
  if (failCount > 0) toast.error(`${failCount} file(s) failed to upload`);
  target.value = "";
  docsPage.value = 1;
  await Promise.all([loadDocs(), loadStatsDocs()]);
}

async function handleDelete(doc: KnowledgeDocument) {
  const accepted = await confirm({
    title: "Delete document",
    message: `Remove "${doc.name}" from the knowledge base? This also removes it from the AI vector store.`,
    destructive: true,
  });
  if (!accepted) return;
  try {
    await deleteKnowledgeDoc(doc.id);
    toast.success("Document deleted");
    if (docs.value.length === 1 && docsPage.value > 1) docsPage.value -= 1;
    await Promise.all([loadDocs(), loadStatsDocs()]);
  } catch {
    toast.error("Could not delete document");
  }
}

async function handleSetup() {
  isSettingUp.value = true;
  try {
    const res = await setupKerisiAI();
    setupResult.value = res.data;
    toast.success("Setup succeeded. Copy the IDs into your .env file.");
  } catch {
    toast.error("Setup failed. Check your API key.");
  } finally {
    isSettingUp.value = false;
  }
}

async function handleUpgrade() {
  isUpgrading.value = true;
  try {
    const res = await upgradeKerisiAssistant();
    toast.success("Assistant upgraded! Tools: " + (res.data as any).tools?.join(", "));
  } catch {
    toast.error("Upgrade failed.");
  } finally {
    isUpgrading.value = false;
  }
}

async function handleSetupUserChat() {
  try {
    isSettingUpUserChat.value = true;
    userChatSetupResult.value = null;
    const res = await setupUserChatAssistant();
    userChatSetupResult.value = {
      assistant_id: res.data.assistant_id,
      message: (res.data as any).message ?? "Add OPENAI_USER_CHAT_ASSISTANT_ID to .env",
    };
    toast.success("User Chat assistant ready");
  } catch (e) {
    const msg = e instanceof Error ? e.message : "Unknown error";
    toast.error(`User Chat setup failed: ${msg}`);
  } finally {
    isSettingUpUserChat.value = false;
  }
}

async function checkDbStatus() {
  dbStatusLoading.value = true;
  try {
    const res = await getDbStatus();
    dbStatus.value = res.data as any;
  } catch {
    dbStatus.value = { connected: false, host: "unknown", database: "unknown" };
  } finally {
    dbStatusLoading.value = false;
  }
}

async function loadLatestTickets() {
  desk365Loading.value = true;
  try {
    const statusRes = await getDesk365Status();
    desk365Status.value = statusRes.data as any;
    if (!desk365Status.value?.configured) {
      desk365Tickets.value = [];
      return;
    }
    const ticketsRes = await getDesk365Tickets(20);
    desk365Tickets.value = ticketsRes.data ?? [];
  } catch {
    desk365Tickets.value = [];
    toast.error("Could not load latest Desk365 tickets");
  } finally {
    desk365Loading.value = false;
  }
}

async function handleSyncDesk365ToAI() {
  desk365Syncing.value = true;
  try {
    const res = await syncDesk365Tickets();
    const d = res.data as any;
    if (d?.success) {
      toast.success(d.message || "Desk365 tickets synced to AI");
      await Promise.all([loadDocs(), loadStatsDocs(), loadLastSyncs()]);
    } else {
      toast.error(d?.message || "Sync failed");
    }
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Sync failed");
  } finally {
    desk365Syncing.value = false;
  }
}

async function loadLastSyncs() {
  try {
    const [extractRes, deskRes, internalRes] = await Promise.all([
      listKnowledgeExtractSyncLogs("?page=1&limit=200"),
      listDesk365SyncLogs("?page=1&limit=50"),
      listInternalTicketSyncLogs("?page=1&limit=50"),
    ]);

    const map: Record<string, string | null> = {
      schema: null,
      lookup: null,
      menu_access: null,
      pages: null,
      bl: null,
      desk365: null,
      internal: null,
    };

    for (const section of ["schema", "lookup", "menu_access", "pages", "bl"] as const) {
      const row = (extractRes.data ?? []).find((r) => r.section === section && r.status === "success");
      map[section] = row?.createdAt ?? null;
    }

    map.desk365 = (deskRes.data ?? []).find((r) => r.status === "success")?.createdAt ?? null;
    map.internal = (internalRes.data ?? []).find((r) => r.status === "success")?.createdAt ?? null;
    lastSyncBySection.value = map;
  } catch {
    // keep previous values silently
  }
}

function formatLastSync(value: string | null | undefined): string {
  if (!value) return "Last sync: —";
  return `Last sync: ${new Date(value).toLocaleString()}`;
}

function formatSize(bytes: number) {
  if (!bytes) return "—";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function totalSize(docList: KnowledgeDocument[]) {
  const total = docList.reduce((sum, d) => sum + (d.fileSize || 0), 0);
  return formatSize(total);
}

function statusIcon(status: string) {
  if (status === "uploaded") return CheckCircle;
  if (status === "failed") return XCircle;
  return Clock;
}

function statusColor(status: string) {
  if (status === "uploaded") return "text-green-600";
  if (status === "failed") return "text-red-500";
  return "text-yellow-500";
}

// Infer document type from name/filename
function inferDocType(doc: KnowledgeDocument): string {
  const name = (doc.name || "").toLowerCase();
  const file = (doc.originalFilename || "").toLowerCase();

  if (file.startsWith("kerisi-lookup-reference")) return "Lookup / Reference";
  if (file.startsWith("kerisi-menu-access")) return "Menu & access";
  if (file.startsWith("kerisi-afsa-tickets") || name.includes("afsa internal")) return "Internal tickets (AI)";
  if (file.startsWith("kerisi-tickets-")) return "Desk365 tickets (AI)";
  if (file.startsWith("tickets-") || name.includes("support ticket")) return "Desk365 tickets (AI)";
  if (file.startsWith("kerisi-bl") || name.startsWith("kerisi bl")) return "Business Logic (BL)";
  if (file.startsWith("kerisi-workflow") || name.includes("workflow")) return "Workflow";
  if (file.startsWith("kerisi-rbac") || name.includes("rbac") || name.includes("capaian peranan")) return "RBAC";
  if (file.startsWith("kerisi-schema") || name.startsWith("kerisi schema") || name.includes("database schema")) return "Database Schema";
  if (file.startsWith("kerisi-pages") || file.startsWith("kerisi-menu")) return "System Knowledge";
  if (name.includes("walkthrough") || file.includes("walkthrough")) return "Walkthrough";
  if (name.includes("user manual") || file.includes("user-manual") || file.includes("user_manual")) return "User Manual";
  if (name.includes("brs") || file.includes("brs")) return "BRS";
  if (file.endsWith(".md")) return "System Knowledge";
  return "Document";
}

// Type icon & colour config
const typeConfig: Record<string, { color: string; bg: string; icon: any }> = {
  "Desk365 tickets (AI)": { color: "text-orange-700", bg: "bg-orange-100", icon: Ticket },
  "Internal tickets (AI)": { color: "text-teal-700", bg: "bg-teal-100", icon: Ticket },
  "Support Tickets": { color: "text-orange-700", bg: "bg-orange-100", icon: Ticket },
  "Lookup / Reference": { color: "text-cyan-800", bg: "bg-cyan-100", icon: BookMarked },
  "Menu & access": { color: "text-violet-800", bg: "bg-violet-100", icon: Map },
  "Business Logic (BL)": { color: "text-purple-700", bg: "bg-purple-100", icon: Code2 },
  Workflow: { color: "text-amber-700", bg: "bg-amber-100", icon: GitBranch },
  RBAC: { color: "text-slate-700", bg: "bg-slate-100", icon: Shield },
  "Database Schema": { color: "text-blue-700", bg: "bg-blue-100", icon: Database },
  "System Knowledge": { color: "text-teal-700", bg: "bg-teal-100", icon: Map },
  Walkthrough: { color: "text-green-700", bg: "bg-green-100", icon: BookMarked },
  "User Manual": { color: "text-indigo-700", bg: "bg-indigo-100", icon: FileSearch },
  BRS: { color: "text-pink-700", bg: "bg-pink-100", icon: BookOpen },
  Document: { color: "text-gray-600", bg: "bg-gray-100", icon: FileText },
};

// Parse ticket_count from notes (for Support Ticket docs)
function getTicketCount(doc: KnowledgeDocument): number {
  if (doc.notes) {
    try {
      const parsed = JSON.parse(doc.notes) as { ticket_count?: number };
      return parsed.ticket_count ?? 0;
    } catch {
      return 0;
    }
  }
  return 0;
}

// Parse bl_count from notes (for Business Logic docs)
function getBLCount(doc: KnowledgeDocument): number {
  if (doc.notes) {
    try {
      const parsed = JSON.parse(doc.notes) as { bl_count?: number };
      return parsed.bl_count ?? 0;
    } catch {
      return 0;
    }
  }
  return 0;
}

// Parse workflow_count from notes (for Workflow docs)
function getWorkflowCount(doc: KnowledgeDocument): number {
  if (doc.notes) {
    try {
      const parsed = JSON.parse(doc.notes) as { workflow_count?: number };
      return parsed.workflow_count ?? 0;
    } catch {
      return 0;
    }
  }
  return 0;
}

const statsSource = computed(() => (statsDocs.value.length > 0 ? statsDocs.value : docs.value));

// Computed stats
const totalDocs    = computed(() => statsSource.value.length);
const uploadedDocs = computed(() => statsSource.value.filter(d => d.status === "uploaded").length);
const failedDocs   = computed(() => statsSource.value.filter(d => d.status === "failed").length);
const pendingDocs  = computed(() => statsSource.value.filter(d => d.status !== "uploaded" && d.status !== "failed").length);

// Ticket-specific stats (Desk365 vs internal — AI documents)
const desk365TicketDocs = computed(() => statsSource.value.filter(d => inferDocType(d) === "Desk365 tickets (AI)"));
const internalTicketDocs = computed(() => statsSource.value.filter(d => inferDocType(d) === "Internal tickets (AI)"));
const ticketDocs = computed(() => [...desk365TicketDocs.value, ...internalTicketDocs.value]);
const totalTicketsDesk365 = computed(() => desk365TicketDocs.value.reduce((sum, d) => sum + getTicketCount(d), 0));
const totalTicketsInternal = computed(() => internalTicketDocs.value.reduce((sum, d) => sum + getTicketCount(d), 0));
const desk365TicketDocCount = computed(() => desk365TicketDocs.value.length);
const internalTicketDocCount = computed(() => internalTicketDocs.value.length);

const avgTicketsPerInternalDoc = computed(() => {
  const n = internalTicketDocCount.value;
  const t = totalTicketsInternal.value;
  if (n <= 0 || t <= 0) return null;
  return Math.round(t / n);
});

// BL-specific stats (Business Logic only)
const blDocs       = computed(() => statsSource.value.filter(d => inferDocType(d) === "Business Logic (BL)"));
const totalBLs     = computed(() => blDocs.value.reduce((sum, d) => sum + getBLCount(d), 0));
const blDocCount   = computed(() => blDocs.value.length);

// Workflow-specific stats
const workflowDocs   = computed(() => statsSource.value.filter(d => inferDocType(d) === "Workflow"));
const totalWorkflows = computed(() => workflowDocs.value.reduce((sum, d) => sum + getWorkflowCount(d), 0));
const workflowDocCount = computed(() => workflowDocs.value.length);

const byType = computed(() => {
  const map: Record<string, { docCount: number; ticketCount: number; blCount: number; workflowCount: number }> = {};
  statsSource.value.forEach(d => {
    const t = inferDocType(d);
    if (!map[t]) map[t] = { docCount: 0, ticketCount: 0, blCount: 0, workflowCount: 0 };
    map[t].docCount++;
    if (t === "Desk365 tickets (AI)" || t === "Internal tickets (AI)" || t === "Support Tickets") map[t].ticketCount += getTicketCount(d);
    if (t === "Business Logic (BL)") map[t].blCount += getBLCount(d);
    if (t === "Workflow") map[t].workflowCount += getWorkflowCount(d);
  });
  return Object.entries(map)
    .map(([type, data]) => ({
      type,
      displayCount: data.ticketCount > 0 ? data.ticketCount
        : data.blCount > 0 ? data.blCount
        : data.workflowCount > 0 ? data.workflowCount
        : data.docCount,
      docCount: data.docCount,
      ticketCount: data.ticketCount,
      blCount: data.blCount,
      workflowCount: data.workflowCount,
    }))
    .sort((a, b) => b.displayCount - a.displayCount);
});

// For progress bar: total "units" = non-special docs + ticket count + BL count + workflow count
const totalDisplayUnits = computed(() => {
  const ticket = ticketDocs.value;
  const bl = blDocs.value;
  const wf = workflowDocs.value;
  const ticketSum = ticket.reduce((s, d) => s + getTicketCount(d), 0);
  const blSum = bl.reduce((s, d) => s + getBLCount(d), 0);
  const wfSum = wf.reduce((s, d) => s + getWorkflowCount(d), 0);
  return totalDocs.value - ticket.length - bl.length - wf.length + ticketSum + blSum + wfSum;
});

const byModule = computed(() => {
  const map: Record<string, number> = {};
  statsSource.value.forEach(d => {
    const m = d.module || "No module";
    map[m] = (map[m] || 0) + 1;
  });
  return Object.entries(map).sort((a, b) => b[1] - a[1]);
});

const moduleColors = [
  "bg-blue-500","bg-green-500","bg-orange-500","bg-purple-500","bg-teal-500",
  "bg-pink-500","bg-indigo-500","bg-yellow-500","bg-red-500","bg-cyan-500",
];
</script>

<template>
  <AdminLayout>
    <div class="max-w-6xl mx-auto p-6 space-y-6">

      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <BarChart2 class="w-6 h-6 text-blue-600" />
            Knowledge Base
          </h1>
          <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">Manage documents and AI grounding for {{ BRANDING.PLATFORM_FULL_NAME }}.</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <button
            @click="handleUpgrade"
            :disabled="isUpgrading"
            class="flex items-center gap-2 text-sm bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white px-4 py-2 rounded-lg transition-colors"
          >
            <Loader2 v-if="isUpgrading" class="w-4 h-4 animate-spin" />
            <Zap v-else class="w-4 h-4" />
            Upgrade AI
          </button>
          <button
            @click="handleSetupUserChat"
            :disabled="isSettingUpUserChat"
            class="flex items-center gap-2 text-sm bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white px-4 py-2 rounded-lg transition-colors"
          >
            <Loader2 v-if="isSettingUpUserChat" class="w-4 h-4 animate-spin" />
            <MessageSquare v-else class="w-4 h-4" />
            Setup User Chat
          </button>
          <button
            @click="handleSetup"
            :disabled="isSettingUp"
            class="flex items-center gap-2 text-sm bg-gray-800 hover:bg-gray-900 disabled:opacity-50 text-white px-4 py-2 rounded-lg transition-colors"
          >
            <Loader2 v-if="isSettingUp" class="w-4 h-4 animate-spin" />
            <Settings v-else class="w-4 h-4" />
            Setup AI
          </button>
        </div>
      </div>

      <!-- ===== STATS TOP ===== -->
      <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
          <p class="text-3xl font-bold text-gray-900">{{ totalDocs }}</p>
          <p class="text-xs text-gray-500 mt-1">Total documents</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
          <p class="text-3xl font-bold text-green-600">{{ uploadedDocs }}</p>
          <p class="text-xs text-gray-500 mt-1">Uploaded</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
          <p class="text-3xl font-bold text-red-500">{{ failedDocs }}</p>
          <p class="text-xs text-gray-500 mt-1">Failed</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
          <p class="text-3xl font-bold text-amber-600">{{ pendingDocs }}</p>
          <p class="text-xs text-gray-500 mt-1">Pending</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
          <p class="text-3xl font-bold text-blue-600">{{ totalSize(docs) }}</p>
          <p class="text-xs text-gray-500 mt-1">Total size</p>
        </div>
      </div>

      <!-- ===== STATISTIK BUSINESS LOGIC (khas) ===== -->
      <div v-if="blDocCount > 0" class="bg-purple-50 rounded-xl border border-purple-200 p-5">
        <h2 class="font-semibold text-purple-800 mb-4 text-sm uppercase tracking-wide flex items-center gap-2">
          <Code2 class="w-4 h-4" /> Business Logic (BL) stats
        </h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-purple-600">{{ totalBLs }}</p>
            <p class="text-xs text-gray-500">BL units</p>
          </div>
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-gray-700">{{ blDocCount }}</p>
            <p class="text-xs text-gray-500">Documents (modules)</p>
          </div>
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-green-600">{{ blDocs.filter(d => d.status === "uploaded").length }}</p>
            <p class="text-xs text-gray-500">In AI</p>
          </div>
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-blue-600">{{ totalBLs > 0 ? Math.round(totalBLs / blDocCount) : 0 }}</p>
            <p class="text-xs text-gray-500">Avg BL / doc</p>
          </div>
        </div>
      </div>

      <!-- ===== STATISTIK TIKET DESK365 (AI) ===== -->
      <div v-if="desk365TicketDocCount > 0" class="bg-orange-50 rounded-xl border border-orange-200 p-5">
        <h2 class="font-semibold text-orange-800 mb-4 text-sm uppercase tracking-wide flex items-center gap-2">
          <Ticket class="w-4 h-4" /> Desk365 tickets (AI) stats
        </h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-orange-600">{{ totalTicketsDesk365 }}</p>
            <p class="text-xs text-gray-500">Tickets</p>
          </div>
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-gray-700">{{ desk365TicketDocCount }}</p>
            <p class="text-xs text-gray-500">Documents</p>
          </div>
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-green-600">{{ desk365TicketDocs.filter(d => d.status === "uploaded").length }}</p>
            <p class="text-xs text-gray-500">In AI</p>
          </div>
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-blue-600">{{ totalTicketsDesk365 > 0 ? Math.round(totalTicketsDesk365 / desk365TicketDocCount) : 0 }}</p>
            <p class="text-xs text-gray-500">Avg tickets / doc</p>
          </div>
        </div>
      </div>

      <!-- ===== Internal ticket AI stats (always shown) ===== -->
      <div class="bg-teal-50 rounded-xl border border-teal-200 p-5">
        <h2 class="font-semibold text-teal-800 mb-4 text-sm uppercase tracking-wide flex items-center gap-2">
          <Ticket class="w-4 h-4" /> Internal tickets (AI) stats
        </h2>
        <p
          v-if="internalTicketDocCount === 0"
          class="text-xs text-teal-800/80 mb-3 rounded-md border border-teal-200/80 bg-white/60 px-3 py-2"
        >
          No internal ticket documents in the vector store yet. Use <strong>Sync to AI</strong> in the internal tickets panel below to build RAG modules; stats fill in after a successful sync.
        </p>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-teal-700">{{ totalTicketsInternal }}</p>
            <p class="text-xs text-gray-500">Tickets</p>
          </div>
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-gray-700">{{ internalTicketDocCount }}</p>
            <p class="text-xs text-gray-500">Documents</p>
          </div>
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-green-600">{{ internalTicketDocs.filter(d => d.status === "uploaded").length }}</p>
            <p class="text-xs text-gray-500">In AI</p>
          </div>
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-blue-600">{{ avgTicketsPerInternalDoc ?? "—" }}</p>
            <p class="text-xs text-gray-500">Avg tickets / doc</p>
          </div>
        </div>
      </div>

      <!-- ===== STATISTIK WORKFLOW (khas) ===== -->
      <div v-if="workflowDocCount > 0" class="bg-amber-50 rounded-xl border border-amber-200 p-5">
        <h2 class="font-semibold text-amber-800 mb-4 text-sm uppercase tracking-wide flex items-center gap-2">
          <GitBranch class="w-4 h-4" /> Workflow stats
        </h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-amber-600">{{ totalWorkflows }}</p>
            <p class="text-xs text-gray-500">Flow pages</p>
          </div>
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-gray-700">{{ workflowDocCount }}</p>
            <p class="text-xs text-gray-500">Documents</p>
          </div>
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-green-600">{{ workflowDocs.filter(d => d.status === "uploaded").length }}</p>
            <p class="text-xs text-gray-500">In AI</p>
          </div>
          <div class="bg-white rounded-lg p-3 text-center">
            <p class="text-2xl font-bold text-blue-600">{{ totalWorkflows > 0 ? Math.round(totalWorkflows / workflowDocCount) : 0 }}</p>
            <p class="text-xs text-gray-500">Avg pages / doc</p>
          </div>
        </div>
      </div>

      <!-- ===== BREAKDOWN: Type + Module ===== -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

        <!-- By Type -->
        <div class="bg-white rounded-xl border border-gray-200 p-5">
          <h2 class="font-semibold text-gray-800 mb-4 text-sm uppercase tracking-wide flex items-center gap-2">
            <FileText class="w-4 h-4 text-gray-400" /> By Type
          </h2>
          <div v-if="byType.length === 0" class="text-sm text-gray-400">No data</div>
          <div v-else class="space-y-2.5">
            <div v-for="item in byType" :key="item.type" class="flex items-center gap-3">
              <div
                class="flex items-center justify-center w-7 h-7 rounded-lg flex-shrink-0"
                :class="typeConfig[item.type]?.bg ?? 'bg-gray-100'"
              >
                <component
                  :is="typeConfig[item.type]?.icon ?? FileText"
                  class="w-3.5 h-3.5"
                  :class="typeConfig[item.type]?.color ?? 'text-gray-500'"
                />
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between mb-1">
                  <span class="text-sm font-medium text-gray-700 truncate">{{ item.type }}</span>
                  <span class="text-sm font-bold text-gray-900 ml-2">
                    {{ item.displayCount }}
                    <span v-if="item.ticketCount > 0" class="text-xs font-normal text-gray-500">({{ item.docCount }} docs)</span>
                    <span v-else-if="item.blCount > 0" class="text-xs font-normal text-gray-500">({{ item.docCount }} modules)</span>
                  </span>
                </div>
                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                  <div
                    class="h-full rounded-full transition-all"
                    :class="typeConfig[item.type]?.bg ?? 'bg-gray-300'"
                    :style="{ width: `${Math.min(100, Math.round((item.displayCount / totalDisplayUnits) * 100))}%` }"
                  />
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- By Module -->
        <div class="bg-white rounded-xl border border-gray-200 p-5">
          <h2 class="font-semibold text-gray-800 mb-4 text-sm uppercase tracking-wide flex items-center gap-2">
            <BookOpen class="w-4 h-4 text-gray-400" /> By module
          </h2>
          <div v-if="byModule.length === 0" class="text-sm text-gray-400">No data</div>
          <div v-else class="space-y-2">
            <div
              v-for="([mod, count], idx) in byModule.slice(0, 12)"
              :key="mod"
              class="flex items-center gap-2"
            >
              <span
                class="inline-block w-2.5 h-2.5 rounded-full flex-shrink-0"
                :class="moduleColors[idx % moduleColors.length]"
              />
              <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between mb-0.5">
                  <span class="text-xs text-gray-700 truncate">{{ mod }}</span>
                  <span class="text-xs font-semibold text-gray-800 ml-2">{{ count }}</span>
                </div>
                <div class="h-1 bg-gray-100 rounded-full overflow-hidden">
                  <div
                    class="h-full rounded-full"
                    :class="moduleColors[idx % moduleColors.length]"
                    :style="{ width: `${Math.round((count / totalDocs) * 100)}%` }"
                  />
                </div>
              </div>
            </div>
            <p v-if="byModule.length > 12" class="text-xs text-gray-400 pt-1">
              + {{ byModule.length - 12 }} more modules
            </p>
          </div>
        </div>
      </div>

      <!-- DB Status Banner -->
      <div
        class="flex items-center gap-3 rounded-xl border px-4 py-3 text-sm"
        :class="dbStatus?.connected
          ? 'bg-green-50 border-green-200 text-green-800'
          : 'bg-red-50 border-red-200 text-red-800'"
      >
        <Database class="w-4 h-4 flex-shrink-0" />
        <div v-if="dbStatus">
          <span class="font-semibold">KERISI Live DB:</span>
          {{ dbStatus.connected ? "✅ Connected" : "❌ Not connected" }}
          <span class="text-xs ml-2 opacity-70">{{ dbStatus.host }} / {{ dbStatus.database }}</span>
        </div>
        <div v-else class="flex items-center gap-2 text-gray-600">
          <Loader2 v-if="dbStatusLoading" class="w-3 h-3 animate-spin" />
          <span class="font-semibold">KERISI Live DB:</span>
          {{ dbStatusLoading ? "Checking connection…" : "Click refresh to test connection" }}
        </div>
        <button @click="checkDbStatus" :disabled="dbStatusLoading" class="ml-auto opacity-60 hover:opacity-100 disabled:opacity-40" title="Check DB connection">
          <RefreshCw class="w-3.5 h-3.5" />
        </button>
      </div>

      <!-- KERISI knowledge extract: schema, lookup, menu access -->
      <div class="space-y-2">
        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm">
          <label class="inline-flex items-center gap-2 text-xs text-gray-700">
            <input v-model="allSyncSelected" type="checkbox" class="h-3.5 w-3.5 rounded border-gray-300" />
            Check all
          </label>
          <button
            type="button"
            @click="handleSyncSelectedToAI"
            :disabled="syncingSelected || selectedSyncTargets.length === 0"
            class="ml-auto flex items-center gap-1.5 text-xs font-medium bg-gray-800 hover:bg-gray-900 disabled:opacity-50 text-white px-3 py-1.5 rounded-lg shrink-0"
          >
            <Loader2 v-if="syncingSelected" class="w-3.5 h-3.5 animate-spin" />
            <Zap v-else class="w-3.5 h-3.5" />
            {{ syncingSelected ? "Syncing one by one..." : "Sync selected (one by one)" }}
          </button>
        </div>
        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-blue-200 bg-blue-50/50 px-3 py-2.5 text-sm">
          <label class="inline-flex items-center gap-1.5 text-xs text-blue-900/90 shrink-0">
            <input v-model="selectedSyncTargets" value="schema" type="checkbox" class="h-3.5 w-3.5 rounded border-blue-300" />
          </label>
          <Database class="w-4 h-4 text-blue-700 shrink-0" />
          <p class="text-xs text-blue-900/90 flex-1 min-w-[12rem]">
            Sync schema.
          </p>
          <button
            type="button"
            @click="handleSyncSchemaToAI"
            :disabled="schemaSyncing"
            class="flex items-center gap-1.5 text-xs font-medium bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white px-3 py-1.5 rounded-lg shrink-0"
          >
            <Loader2 v-if="schemaSyncing" class="w-3.5 h-3.5 animate-spin" />
            <Zap v-else class="w-3.5 h-3.5" />
            {{ schemaSyncing ? "Syncing…" : "Sync" }}
          </button>
          <button type="button" class="text-xs text-blue-800 font-medium hover:underline shrink-0" @click="router.push('/admin/platform/knowledge-extract-log?section=schema')">
            View sync log
          </button>
          <span class="text-[11px] text-blue-900/70 shrink-0">{{ formatLastSync(lastSyncBySection.schema) }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-cyan-200 bg-cyan-50/50 px-3 py-2.5 text-sm">
          <label class="inline-flex items-center gap-1.5 text-xs text-cyan-900/90 shrink-0">
            <input v-model="selectedSyncTargets" value="lookup" type="checkbox" class="h-3.5 w-3.5 rounded border-cyan-300" />
          </label>
          <BookMarked class="w-4 h-4 text-cyan-800 shrink-0" />
          <p class="text-xs text-cyan-950/90 flex-1 min-w-[12rem]">
            Sync lookup.
          </p>
          <button
            type="button"
            @click="handleSyncLookupToAI"
            :disabled="lookupSyncing"
            class="flex items-center gap-1.5 text-xs font-medium bg-cyan-700 hover:bg-cyan-800 disabled:opacity-50 text-white px-3 py-1.5 rounded-lg shrink-0"
          >
            <Loader2 v-if="lookupSyncing" class="w-3.5 h-3.5 animate-spin" />
            <Zap v-else class="w-3.5 h-3.5" />
            {{ lookupSyncing ? "Syncing…" : "Sync" }}
          </button>
          <button type="button" class="text-xs text-cyan-900 font-medium hover:underline shrink-0" @click="router.push('/admin/platform/knowledge-extract-log?section=lookup')">
            View sync log
          </button>
          <span class="text-[11px] text-cyan-900/70 shrink-0">{{ formatLastSync(lastSyncBySection.lookup) }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-violet-200 bg-violet-50/50 px-3 py-2.5 text-sm">
          <label class="inline-flex items-center gap-1.5 text-xs text-violet-900/90 shrink-0">
            <input v-model="selectedSyncTargets" value="menu_access" type="checkbox" class="h-3.5 w-3.5 rounded border-violet-300" />
          </label>
          <Map class="w-4 h-4 text-violet-800 shrink-0" />
          <p class="text-xs text-violet-950/90 flex-1 min-w-[12rem]">
            Sync menu.
          </p>
          <button
            type="button"
            @click="handleSyncMenuAccessToAI"
            :disabled="menuAccessSyncing"
            class="flex items-center gap-1.5 text-xs font-medium bg-violet-700 hover:bg-violet-800 disabled:opacity-50 text-white px-3 py-1.5 rounded-lg shrink-0"
          >
            <Loader2 v-if="menuAccessSyncing" class="w-3.5 h-3.5 animate-spin" />
            <Zap v-else class="w-3.5 h-3.5" />
            {{ menuAccessSyncing ? "Syncing…" : "Sync" }}
          </button>
          <button type="button" class="text-xs text-violet-900 font-medium hover:underline shrink-0" @click="router.push('/admin/platform/knowledge-extract-log?section=menu_access')">
            View sync log
          </button>
          <span class="text-[11px] text-violet-900/70 shrink-0">{{ formatLastSync(lastSyncBySection.menu_access) }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-sm">
          <label class="inline-flex items-center gap-1.5 text-xs text-slate-900/90 shrink-0">
            <input v-model="selectedSyncTargets" value="pages" type="checkbox" class="h-3.5 w-3.5 rounded border-slate-300" />
          </label>
          <FileText class="w-4 h-4 text-slate-800 shrink-0" />
          <p class="text-xs text-slate-900/90 flex-1 min-w-[12rem]">
            Sync page.
          </p>
          <button
            type="button"
            @click="handleSyncPagesToAI"
            :disabled="pagesSyncing"
            class="flex items-center gap-1.5 text-xs font-medium bg-slate-700 hover:bg-slate-800 disabled:opacity-50 text-white px-3 py-1.5 rounded-lg shrink-0"
          >
            <Loader2 v-if="pagesSyncing" class="w-3.5 h-3.5 animate-spin" />
            <Zap v-else class="w-3.5 h-3.5" />
            {{ pagesSyncing ? "Syncing…" : "Sync" }}
          </button>
          <button type="button" class="text-xs text-slate-900 font-medium hover:underline shrink-0" @click="router.push('/admin/platform/knowledge-extract-log?section=pages')">
            View sync log
          </button>
          <span class="text-[11px] text-slate-900/70 shrink-0">{{ formatLastSync(lastSyncBySection.pages) }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-purple-200 bg-purple-50/50 px-3 py-2.5 text-sm">
          <label class="inline-flex items-center gap-1.5 text-xs text-purple-900/90 shrink-0">
            <input v-model="selectedSyncTargets" value="bl" type="checkbox" class="h-3.5 w-3.5 rounded border-purple-300" />
          </label>
          <Code2 class="w-4 h-4 text-purple-800 shrink-0" />
          <p class="text-xs text-purple-900/90 flex-1 min-w-[12rem]">
            Sync business logic.
          </p>
          <button
            type="button"
            @click="handleSyncBlToAI"
            :disabled="blSyncing"
            class="flex items-center gap-1.5 text-xs font-medium bg-purple-700 hover:bg-purple-800 disabled:opacity-50 text-white px-3 py-1.5 rounded-lg shrink-0"
          >
            <Loader2 v-if="blSyncing" class="w-3.5 h-3.5 animate-spin" />
            <Zap v-else class="w-3.5 h-3.5" />
            {{ blSyncing ? "Syncing…" : "Sync" }}
          </button>
          <button type="button" class="text-xs text-purple-900 font-medium hover:underline shrink-0" @click="router.push('/admin/platform/knowledge-extract-log?section=bl')">
            View sync log
          </button>
          <span class="text-[11px] text-purple-900/70 shrink-0">{{ formatLastSync(lastSyncBySection.bl) }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-orange-200 bg-orange-50/50 px-3 py-2.5 text-sm">
          <label class="inline-flex items-center gap-1.5 text-xs text-orange-900/90 shrink-0">
            <input v-model="selectedSyncTargets" value="desk365" type="checkbox" class="h-3.5 w-3.5 rounded border-orange-300" />
          </label>
          <Ticket class="w-4 h-4 text-orange-700 shrink-0" />
          <p class="text-xs text-orange-900/90 flex-1 min-w-[12rem]">Sync Desk365.</p>
          <button
            type="button"
            @click="handleSyncDesk365ToAI"
            :disabled="desk365Syncing || !desk365CanSync"
            class="flex items-center gap-1.5 text-xs font-medium bg-orange-600 hover:bg-orange-700 disabled:opacity-50 text-white px-3 py-1.5 rounded-lg shrink-0"
          >
            <Loader2 v-if="desk365Syncing" class="w-3.5 h-3.5 animate-spin" />
            <Zap v-else class="w-3.5 h-3.5" />
            {{ desk365Syncing ? "Syncing…" : "Sync" }}
          </button>
          <button type="button" class="text-xs text-orange-900 font-medium hover:underline shrink-0" @click="router.push('/admin/platform/desk365')">
            View sync log
          </button>
          <span class="text-[11px] text-orange-900/70 shrink-0">{{ formatLastSync(lastSyncBySection.desk365) }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-teal-200 bg-teal-50/50 px-3 py-2.5 text-sm">
          <label class="inline-flex items-center gap-1.5 text-xs text-teal-900/90 shrink-0">
            <input v-model="selectedSyncTargets" value="internal" type="checkbox" class="h-3.5 w-3.5 rounded border-teal-300" />
          </label>
          <Ticket class="w-4 h-4 text-teal-700 shrink-0" />
          <p class="text-xs text-teal-900/90 flex-1 min-w-[12rem]">Sync internal.</p>
          <button
            type="button"
            @click="handleSyncInternalToAI"
            :disabled="internalSyncing"
            class="flex items-center gap-1.5 text-xs font-medium bg-teal-600 hover:bg-teal-700 disabled:opacity-50 text-white px-3 py-1.5 rounded-lg shrink-0"
          >
            <Loader2 v-if="internalSyncing" class="w-3.5 h-3.5 animate-spin" />
            <Zap v-else class="w-3.5 h-3.5" />
            {{ internalSyncing ? "Syncing…" : "Sync" }}
          </button>
          <button type="button" class="text-xs text-teal-900 font-medium hover:underline shrink-0" @click="router.push('/admin/platform/ticket-log')">
            View sync log
          </button>
          <span class="text-[11px] text-teal-900/70 shrink-0">{{ formatLastSync(lastSyncBySection.internal) }}</span>
        </div>
      </div>

      <!-- Setup result -->
      <div v-if="setupResult" class="bg-green-50 border border-green-200 rounded-xl p-4 text-sm">
        <p class="font-semibold text-green-800 mb-2">Setup complete — add these to your .env:</p>
        <code class="block bg-white border border-green-100 rounded p-3 text-xs text-gray-800">
          OPENAI_VECTOR_STORE_ID={{ setupResult.vector_store_id }}<br>
          OPENAI_ASSISTANT_ID={{ setupResult.assistant_id }}
        </code>
        <p class="text-green-700 mt-2">Restart the app after updating .env.</p>
      </div>

      <!-- User Chat setup result -->
      <div v-if="userChatSetupResult" class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm">
        <p class="font-semibold text-emerald-800 mb-2">User chat assistant ready — add to .env:</p>
        <code class="block bg-white border border-emerald-100 rounded p-3 text-xs text-gray-800">
          OPENAI_USER_CHAT_ASSISTANT_ID={{ userChatSetupResult.assistant_id }}
        </code>
        <p class="text-emerald-700 mt-2">{{ userChatSetupResult.message }}</p>
      </div>

      <!-- Upload panel -->
      <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
          <Upload class="w-4 h-4" /> Upload documents
        </h2>
        <div class="flex items-center gap-3 flex-wrap">
          <select
            v-model="selectedModule"
            class="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            <option value="">Module (optional)</option>
            <option v-for="mod in modules" :key="mod" :value="mod">{{ mod }}</option>
          </select>
          <button
            type="button"
            @click="triggerUpload"
            :disabled="isUploading"
            class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm px-4 py-2 rounded-lg transition-colors"
          >
            <Loader2 v-if="isUploading" class="w-4 h-4 animate-spin" />
            <Upload v-else class="w-4 h-4" />
            {{ isUploading ? "Uploading…" : "Choose files (DOCX / PDF)" }}
          </button>
          <span class="text-xs text-gray-400">You can select multiple files.</span>
        </div>
        <input
          ref="fileInput"
          type="file"
          multiple
          accept=".docx,.doc,.pdf,.txt"
          class="hidden"
          @change="handleFileChange"
        />
      </div>

      <!-- Filter & Search -->
      <div class="flex gap-3 flex-wrap items-center">
        <input
          v-model="searchQ"
          @input="onSearchInput"
          type="text"
          placeholder="Search documents…"
          class="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 w-64"
        />
        <select
          v-model="filterModule"
          @change="onFilterModuleChange"
          class="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="">All modules</option>
          <option v-for="mod in modules" :key="mod" :value="mod">{{ mod }}</option>
        </select>
        <button type="button" @click="docsPage = 1; loadDocs()" class="text-gray-400 hover:text-gray-600 transition-colors" title="Refresh list">
          <RefreshCw class="w-4 h-4" />
        </button>
        <span class="text-xs text-gray-400 ml-auto">{{ docsTotal }} total</span>
      </div>

      <!-- Documents table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div v-if="isLoading" class="flex items-center justify-center py-12">
          <Loader2 class="w-6 h-6 animate-spin text-gray-400" />
        </div>

        <div v-else-if="docs.length === 0" class="flex flex-col items-center justify-center py-12 text-gray-400">
          <BookOpen class="w-10 h-10 mb-3" />
          <p class="text-sm">No documents yet. Upload files to get started.</p>
        </div>

        <table v-else class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
              <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Document</th>
              <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Type</th>
              <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Module</th>
              <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Size</th>
              <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
              <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="doc in docs" :key="doc.id" class="hover:bg-gray-50 transition-colors">
              <td class="px-4 py-3">
                <div class="flex items-center gap-2">
                  <FileText class="w-4 h-4 text-blue-400 flex-shrink-0" />
                  <div>
                    <p class="font-medium text-gray-800 truncate max-w-xs">{{ doc.name }}</p>
                    <p class="text-xs text-gray-400 truncate max-w-xs">{{ doc.originalFilename }}</p>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
                  :class="[typeConfig[inferDocType(doc)]?.bg ?? 'bg-gray-100', typeConfig[inferDocType(doc)]?.color ?? 'text-gray-600']"
                >
                  <component :is="typeConfig[inferDocType(doc)]?.icon ?? FileText" class="w-3 h-3" />
                  {{ inferDocType(doc) }}
                </span>
              </td>
              <td class="px-4 py-3">
                <span v-if="doc.module" class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                  {{ doc.module }}
                </span>
                <span v-else class="text-gray-400 text-xs">—</span>
              </td>
              <td class="px-4 py-3 text-gray-500 text-xs">{{ formatSize(doc.fileSize) }}</td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-1.5" :class="statusColor(doc.status)">
                  <component :is="statusIcon(doc.status)" class="w-4 h-4" />
                  <span class="text-xs font-medium capitalize">{{ doc.status }}</span>
                </div>
              </td>
              <td class="px-4 py-3 text-right">
                <button
                  @click="handleDelete(doc)"
                  class="text-gray-300 hover:text-red-500 transition-colors"
                >
                  <Trash2 class="w-4 h-4" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <div class="flex items-center justify-between border-t border-gray-100 px-4 py-2 text-xs text-gray-500">
          <span>Page {{ docsPage }} / {{ docsTotalPages }}</span>
          <div class="flex items-center gap-2">
            <button type="button" class="px-2 py-1 rounded border border-gray-200 disabled:opacity-40" :disabled="docsPage <= 1 || isLoading" @click="prevDocsPage">Prev</button>
            <button type="button" class="px-2 py-1 rounded border border-gray-200 disabled:opacity-40" :disabled="docsPage >= docsTotalPages || isLoading" @click="nextDocsPage">Next</button>
          </div>
        </div>
      </div>

    </div>
  </AdminLayout>
</template>
