<script setup lang="ts">
import { onMounted, ref } from "vue";
import {
  Settings,
  Globe,
  Image,
  Search,
  Save,
  CheckCircle2,
  Upload,
  Trash2,
  FolderOpen,
  X,
  List,
  Plus,
} from "lucide-vue-next";

import AdminLayout from "@/layouts/AdminLayout.vue";
import { getSettings, updateSettings, getLookups, updateLookups, uploadMedia, listMedia, listPages } from "@/api/cms";
import { useConfirmDialog } from "@/composables/useConfirmDialog";
import { useToast } from "@/composables/useToast";
import { API_BASE_URL } from "@/env";
import { useSiteStore } from "@/stores/site";
import { useRoute } from "vue-router";
import type { CodeDescLookupRow, Media, Page, SettingsPayload, UserLevelLookupRow } from "@/types";

const site = useSiteStore();
const route = useRoute();
const toast = useToast();
const confirmDialog = useConfirmDialog();

const form = ref<SettingsPayload>({
  siteTitle: "",
  tagline: "",
  webfrontTitle: "",
  webfrontTagline: "",
  titleFormat: "%page% | %site%",
  metaDescription: "",
  siteIconUrl: "",
  webfrontLogoUrl: "",
  sidebarLogoUrl: "",
  faviconUrl: "",
  language: "en",
  timezone: "UTC",
  footerText: "",
  frontPageId: null,
});

const saved = ref(false);
const saving = ref(false);
const error = ref("");
const uploadingSiteIcon = ref(false);
const uploadingSidebarLogo = ref(false);
const uploadingFavicon = ref(false);

const mediaPickerOpen = ref(false);
const mediaPickerTarget = ref<"siteIconUrl" | "faviconUrl" | "sidebarLogoUrl">("siteIconUrl");
const mediaPickerItems = ref<Media[]>([]);
const mediaPickerLoading = ref(false);
const publishedPages = ref<Page[]>([]);

const DEFAULT_LOOKUPS = {
  system: ["KERISI", "iAGC", "eGPA"],
} as const;

const DEFAULT_USER_LEVEL_LOOKUP: UserLevelLookupRow[] = [
  { code: "0", desc: "developer" },
  { code: "1", desc: "admin internal" },
  { code: "2", desc: "admin external" },
  { code: "3", desc: "agent" },
  { code: "4", desc: "user" },
  { code: "5", desc: "secondary user" },
];

const DEFAULT_USER_CATEGORY_LOOKUP: CodeDescLookupRow[] = [
  { code: "tempatan", desc: "user tempatan" },
  { code: "luar_negara", desc: "luar negara" },
];

const DEFAULT_USER_SEGMENT_LOOKUP: CodeDescLookupRow[] = [
  { code: "1", desc: "Government" },
  { code: "2", desc: "Private" },
];

const DEFAULT_USER_JENIS_PENGGUNA_LOOKUP: CodeDescLookupRow[] = [
  { code: "1", desc: "Tempatan" },
  { code: "2", desc: "Luar negara" },
];

const lookups = ref<{
  system: string[];
  userLevel: UserLevelLookupRow[];
  userCategory: CodeDescLookupRow[];
  userSegment: CodeDescLookupRow[];
  userJenisPengguna: CodeDescLookupRow[];
}>({
  system: [...DEFAULT_LOOKUPS.system],
  userLevel: DEFAULT_USER_LEVEL_LOOKUP.map((r) => ({ ...r })),
  userCategory: DEFAULT_USER_CATEGORY_LOOKUP.map((r) => ({ ...r })),
  userSegment: DEFAULT_USER_SEGMENT_LOOKUP.map((r) => ({ ...r })),
  userJenisPengguna: DEFAULT_USER_JENIS_PENGGUNA_LOOKUP.map((r) => ({ ...r })),
});
const lookupSystemNew = ref("");
const lookupUserLevelCode = ref("");
const lookupUserLevelDesc = ref("");
const lookupUserCategoryCode = ref("");
const lookupUserCategoryDesc = ref("");
const lookupUserSegmentCode = ref("");
const lookupUserSegmentDesc = ref("");
const lookupUserJenisPenggunaCode = ref("");
const lookupUserJenisPenggunaDesc = ref("");

function normalizeUserLevelFromApi(raw: unknown): UserLevelLookupRow[] {
  if (!Array.isArray(raw) || raw.length === 0) {
    return DEFAULT_USER_LEVEL_LOOKUP.map((r) => ({ ...r }));
  }
  const first = raw[0];
  if (typeof first === "string") {
    const out: UserLevelLookupRow[] = [];
    for (const s of raw as string[]) {
      const m = String(s).match(/^\s*(\S+)\s*-\s*(.+)$/u);
      if (m) {
        out.push({ code: m[1].trim(), desc: m[2].trim() });
      }
    }
    return out.length > 0 ? out : DEFAULT_USER_LEVEL_LOOKUP.map((r) => ({ ...r }));
  }
  const rows: UserLevelLookupRow[] = [];
  for (const r of raw as { code?: unknown; desc?: unknown; label?: unknown }[]) {
    const code = String(r?.code ?? "").trim();
    let desc = String(r?.desc ?? "").trim();
    if (!desc && r?.label != null) {
      desc = String(r.label).trim();
    }
    if (code && desc) {
      rows.push({ code, desc });
    }
  }
  return rows.length > 0 ? rows : DEFAULT_USER_LEVEL_LOOKUP.map((r) => ({ ...r }));
}

function normalizeUserCategoryFromApi(raw: unknown): CodeDescLookupRow[] {
  if (!Array.isArray(raw) || raw.length === 0) {
    return DEFAULT_USER_CATEGORY_LOOKUP.map((r) => ({ ...r }));
  }
  const first = raw[0];
  if (typeof first === "string") {
    const out: CodeDescLookupRow[] = [];
    for (const s of raw as string[]) {
      const m = String(s).match(/^\s*(\S+)\s*-\s*(.+)$/u);
      if (m) {
        out.push({ code: m[1].trim(), desc: m[2].trim() });
      }
    }
    return out.length > 0 ? out : DEFAULT_USER_CATEGORY_LOOKUP.map((r) => ({ ...r }));
  }
  const rows: CodeDescLookupRow[] = [];
  for (const r of raw as { code?: unknown; desc?: unknown; label?: unknown }[]) {
    const code = String(r?.code ?? "").trim();
    let desc = String(r?.desc ?? "").trim();
    if (!desc && r?.label != null) {
      desc = String(r.label).trim();
    }
    if (code && desc) {
      rows.push({ code, desc });
    }
  }
  return rows.length > 0 ? rows : DEFAULT_USER_CATEGORY_LOOKUP.map((r) => ({ ...r }));
}

function normalizeUserSegmentFromApi(raw: unknown): CodeDescLookupRow[] {
  if (!Array.isArray(raw) || raw.length === 0) {
    return DEFAULT_USER_SEGMENT_LOOKUP.map((r) => ({ ...r }));
  }
  const first = raw[0];
  if (typeof first === "string") {
    const out: CodeDescLookupRow[] = [];
    for (const s of raw as string[]) {
      const m = String(s).match(/^\s*(\S+)\s*-\s*(.+)$/u);
      if (m) {
        out.push({ code: m[1].trim(), desc: m[2].trim() });
      }
    }
    return out.length > 0 ? out : DEFAULT_USER_SEGMENT_LOOKUP.map((r) => ({ ...r }));
  }
  const rows: CodeDescLookupRow[] = [];
  for (const r of raw as { code?: unknown; desc?: unknown; label?: unknown }[]) {
    const code = String(r?.code ?? "").trim();
    let desc = String(r?.desc ?? "").trim();
    if (!desc && r?.label != null) {
      desc = String(r.label).trim();
    }
    if (code && desc) {
      rows.push({ code, desc });
    }
  }
  return rows.length > 0 ? rows : DEFAULT_USER_SEGMENT_LOOKUP.map((r) => ({ ...r }));
}

function normalizeUserJenisPenggunaFromApi(raw: unknown): CodeDescLookupRow[] {
  if (!Array.isArray(raw) || raw.length === 0) {
    return DEFAULT_USER_JENIS_PENGGUNA_LOOKUP.map((r) => ({ ...r }));
  }
  const first = raw[0];
  if (typeof first === "string") {
    const out: CodeDescLookupRow[] = [];
    for (const s of raw as string[]) {
      const m = String(s).match(/^\s*(\S+)\s*-\s*(.+)$/u);
      if (m) {
        out.push({ code: m[1].trim(), desc: m[2].trim() });
      }
    }
    return out.length > 0 ? out : DEFAULT_USER_JENIS_PENGGUNA_LOOKUP.map((r) => ({ ...r }));
  }
  const rows: CodeDescLookupRow[] = [];
  for (const r of raw as { code?: unknown; desc?: unknown; label?: unknown }[]) {
    const code = String(r?.code ?? "").trim();
    let desc = String(r?.desc ?? "").trim();
    if (!desc && r?.label != null) {
      desc = String(r.label).trim();
    }
    if (code && desc) {
      rows.push({ code, desc });
    }
  }
  return rows.length > 0 ? rows : DEFAULT_USER_JENIS_PENGGUNA_LOOKUP.map((r) => ({ ...r }));
}

async function openMediaPicker(target: typeof mediaPickerTarget.value) {
  mediaPickerTarget.value = target;
  mediaPickerOpen.value = true;
  mediaPickerLoading.value = true;
  try {
    const res = await listMedia();
    mediaPickerItems.value = res.data.filter((m: Media) => m.mimeType.startsWith("image/"));
  } catch {
    mediaPickerItems.value = [];
  } finally {
    mediaPickerLoading.value = false;
  }
}

function selectFromLibrary(item: Media) {
  form.value[mediaPickerTarget.value] = item.url;
  mediaPickerOpen.value = false;
}

function resolveUrl(url: string) {
  if (!url) return "";
  if (url.startsWith("http")) return url;
  return `${API_BASE_URL}${url}`;
}

async function onSiteIconUpload(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  uploadingSiteIcon.value = true;
  error.value = "";
  try {
    const res = await uploadMedia(file);
    form.value.siteIconUrl = res.data.url;
    toast.success("Site icon uploaded");
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : "Failed to upload site icon";
    toast.error("Upload failed", error.value);
  } finally {
    uploadingSiteIcon.value = false;
    (event.target as HTMLInputElement).value = "";
  }
}

async function onFaviconUpload(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  uploadingFavicon.value = true;
  error.value = "";
  try {
    const res = await uploadMedia(file);
    form.value.faviconUrl = res.data.url;
    toast.success("Favicon uploaded");
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : "Failed to upload favicon";
    toast.error("Upload failed", error.value);
  } finally {
    uploadingFavicon.value = false;
    (event.target as HTMLInputElement).value = "";
  }
}

async function onSidebarLogoUpload(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  uploadingSidebarLogo.value = true;
  error.value = "";
  try {
    const res = await uploadMedia(file);
    form.value.sidebarLogoUrl = res.data.url;
    toast.success("Sidebar logo uploaded");
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : "Failed to upload sidebar logo";
    toast.error("Upload failed", error.value);
  } finally {
    uploadingSidebarLogo.value = false;
    (event.target as HTMLInputElement).value = "";
  }
}

async function load() {
  try {
    const [settingsResponse, pagesResponse, lookupsResponse] = await Promise.all([
      getSettings(),
      listPages("?status=published&page=1&limit=100&sortBy=updatedAt&sortDir=desc"),
      getLookups().catch(() => null),
    ]);
    form.value = settingsResponse.data;
    publishedPages.value = pagesResponse.data;
    const sd = settingsResponse.data;
    const lu = lookupsResponse?.data;
    const mergedSystem = lu?.system?.length ? lu.system : sd.lookupSystem;
    const mergedUserLevel = lu?.userLevel?.length ? lu.userLevel : sd.lookupUserLevel;
    const mergedUserCategory = lu?.userCategory?.length ? lu.userCategory : sd.lookupUserCategory;
    const mergedUserSegment = lu?.userSegment?.length ? lu.userSegment : sd.lookupUserSegment;
    const mergedUserJenisPengguna = lu?.userJenisPengguna?.length
      ? lu.userJenisPengguna
      : sd.lookupUserJenisPengguna;
    lookups.value = {
      system: mergedSystem?.length ? mergedSystem : [...DEFAULT_LOOKUPS.system],
      userLevel: normalizeUserLevelFromApi(mergedUserLevel ?? []),
      userCategory: normalizeUserCategoryFromApi(mergedUserCategory ?? []),
      userSegment: normalizeUserSegmentFromApi(mergedUserSegment ?? []),
      userJenisPengguna: normalizeUserJenisPenggunaFromApi(mergedUserJenisPengguna ?? []),
    };
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : "Failed to load settings";
  }
}

function addLookupSystem() {
  const v = lookupSystemNew.value.trim();
  if (v && !lookups.value.system.includes(v)) {
    lookups.value.system = [...lookups.value.system, v];
    lookupSystemNew.value = "";
  }
}

function removeLookupSystem(idx: number) {
  lookups.value.system = lookups.value.system.filter((_, i) => i !== idx);
}

function addLookupUserLevel() {
  const code = lookupUserLevelCode.value.trim();
  const desc = lookupUserLevelDesc.value.trim();
  if (!code || !desc) {
    return;
  }
  if (lookups.value.userLevel.some((r) => r.code === code)) {
    toast.error("That code is already in the list");
    return;
  }
  lookups.value.userLevel = [...lookups.value.userLevel, { code, desc }];
  lookupUserLevelCode.value = "";
  lookupUserLevelDesc.value = "";
}

function removeLookupUserLevel(idx: number) {
  lookups.value.userLevel = lookups.value.userLevel.filter((_, i) => i !== idx);
}

function addLookupUserCategory() {
  const code = lookupUserCategoryCode.value.trim();
  const desc = lookupUserCategoryDesc.value.trim();
  if (!code || !desc) {
    return;
  }
  if (lookups.value.userCategory.some((r) => r.code === code)) {
    toast.error("That code is already in the list");
    return;
  }
  lookups.value.userCategory = [...lookups.value.userCategory, { code, desc }];
  lookupUserCategoryCode.value = "";
  lookupUserCategoryDesc.value = "";
}

function removeLookupUserCategory(idx: number) {
  lookups.value.userCategory = lookups.value.userCategory.filter((_, i) => i !== idx);
}

function addLookupUserSegment() {
  const code = lookupUserSegmentCode.value.trim();
  const desc = lookupUserSegmentDesc.value.trim();
  if (!code || !desc) {
    return;
  }
  if (lookups.value.userSegment.some((r) => r.code === code)) {
    toast.error("That code is already in the list");
    return;
  }
  lookups.value.userSegment = [...lookups.value.userSegment, { code, desc }];
  lookupUserSegmentCode.value = "";
  lookupUserSegmentDesc.value = "";
}

function removeLookupUserSegment(idx: number) {
  lookups.value.userSegment = lookups.value.userSegment.filter((_, i) => i !== idx);
}

function addLookupUserJenisPengguna() {
  const code = lookupUserJenisPenggunaCode.value.trim();
  const desc = lookupUserJenisPenggunaDesc.value.trim();
  if (!code || !desc) {
    return;
  }
  if (lookups.value.userJenisPengguna.some((r) => r.code === code)) {
    toast.error("That code is already in the list");
    return;
  }
  lookups.value.userJenisPengguna = [...lookups.value.userJenisPengguna, { code, desc }];
  lookupUserJenisPenggunaCode.value = "";
  lookupUserJenisPenggunaDesc.value = "";
}

function removeLookupUserJenisPengguna(idx: number) {
  lookups.value.userJenisPengguna = lookups.value.userJenisPengguna.filter((_, i) => i !== idx);
}

async function saveLookups() {
  try {
    await updateLookups({
      system: lookups.value.system,
      userLevel: lookups.value.userLevel,
      userCategory: lookups.value.userCategory,
      userSegment: lookups.value.userSegment,
      userJenisPengguna: lookups.value.userJenisPengguna,
    });
    toast.success("Lookups saved");
  } catch (e: unknown) {
    toast.error("Failed to save lookups", e instanceof Error ? e.message : undefined);
  }
}

async function save() {
  saving.value = true;
  error.value = "";
  try {
    await updateSettings(form.value);
    site.applyFrom(form.value);
    site.setDocumentTitle((route.meta.title as string) || "Settings");
    saved.value = true;
    toast.success("Settings saved");
    setTimeout(() => {
      saved.value = false;
    }, 2000);
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : "Failed to save settings";
    toast.error("Save failed", error.value);
  } finally {
    saving.value = false;
  }
}

async function removeAsset(key: "siteIconUrl" | "faviconUrl" | "sidebarLogoUrl", label: string) {
  if (!form.value[key]) return;
  const allowed = await confirmDialog.confirm({
    title: `Remove ${label}?`,
    message: "This will clear the current image reference.",
    confirmText: "Remove",
    destructive: true,
  });
  if (!allowed) return;
  form.value[key] = "";
  toast.info(`${label} removed`);
}

onMounted(load);
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-7xl space-y-4">
      <!-- ───── Hero Header ───── -->
      <div class="flex items-center justify-between">
        <h1 class="page-title">Settings</h1>
      </div>

      <div class="space-y-4">
        <!-- ═══════ GENERAL ═══════ -->
        <article class="rounded-lg border border-slate-200 bg-white shadow-sm">
          <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
            <Globe class="h-4 w-4 text-violet-600" />
            <h2 class="text-sm font-semibold text-slate-900">General</h2>
          </div>
          <div class="p-4">
            <div class="grid gap-3 md:grid-cols-3">
              <div class="space-y-1.5">
                <label class="text-sm font-medium text-slate-700">Site Title</label>
                <input v-model="form.siteTitle" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200" />
              </div>
              <div class="space-y-1.5">
                <label class="text-sm font-medium text-slate-700">Tagline</label>
                <input v-model="form.tagline" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200" />
              </div>
              <div class="space-y-1.5">
                <label class="text-sm font-medium text-slate-700">Language</label>
                <input v-model="form.language" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200" />
              </div>
              <div class="space-y-1.5">
                <label class="text-sm font-medium text-slate-700">Timezone</label>
                <input v-model="form.timezone" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200" />
              </div>
              <div class="space-y-1.5 md:col-span-2">
                <label class="text-sm font-medium text-slate-700">Footer Text</label>
                <input v-model="form.footerText" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="e.g. © 2026 My Company" />
                <p class="text-xs text-slate-400">Displayed at the bottom of the sidebar.</p>
              </div>
              <div class="space-y-1.5 md:col-span-2">
                <label class="text-sm font-medium text-slate-700">Front Page</label>
                <select
                  v-model="form.frontPageId"
                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
                >
                  <option :value="null">None (use fallback)</option>
                  <option v-for="page in publishedPages" :key="page.id" :value="page.id">
                    {{ page.title }} ({{ page.slug }})
                  </option>
                </select>
                <p class="text-xs text-slate-400">Select which published page is shown at Webfront homepage (`/`).</p>
                <p
                  v-if="form.frontPageId !== null && !publishedPages.some((page) => page.id === form.frontPageId)"
                  class="text-xs text-amber-600"
                >
                  Selected front page is no longer published or missing. Webfront will use fallback order.
                </p>
              </div>
            </div>
          </div>
        </article>

        <!-- ═══════ SEO ═══════ -->
        <article class="rounded-lg border border-slate-200 bg-white shadow-sm">
          <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
            <Search class="h-4 w-4 text-blue-600" />
            <h2 class="text-sm font-semibold text-slate-900">SEO</h2>
          </div>
          <div class="p-4">
            <div class="grid gap-3 md:grid-cols-2">
              <div class="space-y-1.5 md:col-span-2">
                <label class="text-sm font-medium text-slate-700">Title Format</label>
                <input v-model="form.titleFormat" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200" />
                <p class="text-xs text-slate-400">Use <code class="rounded bg-slate-100 px-1 py-0.5 text-xs">%page%</code> and <code class="rounded bg-slate-100 px-1 py-0.5 text-xs">%site%</code> as placeholders.</p>
              </div>
              <div class="space-y-1.5 md:col-span-2">
                <label class="text-sm font-medium text-slate-700">Meta Description</label>
                <textarea v-model="form.metaDescription" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200" />
              </div>
            </div>
          </div>
        </article>

        <!-- ═══════ LOOKUPS ═══════ -->
        <article class="rounded-lg border border-slate-200 bg-white shadow-sm">
          <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
            <List class="h-4 w-4 text-violet-600" />
            <h2 class="text-sm font-semibold text-slate-900">Lookups</h2>
          </div>
          <div class="p-4 space-y-4">
            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700">System (KERISI, iAGC, eGPA)</label>
              <p class="mb-2 text-xs text-slate-500">Options for the System dropdown in Customer form.</p>
              <div class="flex flex-wrap gap-2 mb-2">
                <span
                  v-for="(opt, idx) in lookups.system"
                  :key="opt"
                  class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700"
                >
                  {{ opt }}
                  <button type="button" class="rounded p-0.5 text-slate-400 hover:bg-slate-200 hover:text-slate-600" @click="removeLookupSystem(idx)">×</button>
                </span>
              </div>
              <div class="flex gap-2">
                <input
                  v-model="lookupSystemNew"
                  type="text"
                  class="rounded-lg border border-slate-300 px-3 py-2 text-sm w-40"
                  placeholder="Add option"
                  @keydown.enter.prevent="addLookupSystem"
                />
                <button type="button" class="flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="addLookupSystem">
                  <Plus class="h-4 w-4" />
                  Add
                </button>
              </div>
            </div>

            <div class="border-t border-slate-100 pt-4">
              <label class="mb-2 block text-sm font-medium text-slate-700">User level (code + description)</label>
              <p class="mb-2 text-xs text-slate-500">
                Display reference for hierarchy levels. Actual values in the database stay
                <code class="rounded bg-slate-100 px-1 py-0.5 text-[11px]">super_admin</code>,
                <code class="rounded bg-slate-100 px-1 py-0.5 text-[11px]">internal_admin</code>, etc.
                <span class="mt-1 block">
                  On create/edit user, rows whose <strong class="font-medium">code</strong> maps to tier
                  <strong class="font-medium">0–5</strong> (or names like <code class="rounded bg-slate-100 px-1 py-0.5 text-[11px]">internal_admin</code>,
                  <code class="rounded bg-slate-100 px-1 py-0.5 text-[11px]">secondary_user</code>,
                  <code class="rounded bg-slate-100 px-1 py-0.5 text-[11px]">l5</code>) appear in the User Level dropdown. Codes with no mapping stay reference-only.
                </span>
              </p>
              <div class="flex flex-wrap gap-2 mb-2">
                <span
                  v-for="(row, idx) in lookups.userLevel"
                  :key="`ul-${idx}-${row.code}`"
                  class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700"
                >
                  <span class="font-mono text-xs text-slate-600">{{ row.code }}</span>
                  <span class="text-slate-400">·</span>
                  <span>{{ row.desc }}</span>
                  <button type="button" class="rounded p-0.5 text-slate-400 hover:bg-slate-200 hover:text-slate-600" @click="removeLookupUserLevel(idx)">×</button>
                </span>
              </div>
              <div class="flex flex-wrap items-end gap-2">
                <div class="space-y-1">
                  <label class="block text-xs font-medium text-slate-600">Code</label>
                  <input
                    v-model="lookupUserLevelCode"
                    type="text"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm w-20 font-mono"
                    placeholder="0"
                    @keydown.enter.prevent="addLookupUserLevel"
                  />
                </div>
                <div class="space-y-1 flex-1 min-w-[10rem]">
                  <label class="block text-xs font-medium text-slate-600">Description</label>
                  <input
                    v-model="lookupUserLevelDesc"
                    type="text"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm w-full max-w-xs"
                    placeholder="developer"
                    @keydown.enter.prevent="addLookupUserLevel"
                  />
                </div>
                <button type="button" class="flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="addLookupUserLevel">
                  <Plus class="h-4 w-4" />
                  Add
                </button>
              </div>
            </div>

            <div class="border-t border-slate-100 pt-4">
              <label class="mb-2 block text-sm font-medium text-slate-700">User category (code + description)</label>
              <p class="mb-2 text-xs text-slate-500">
                Rujukan pilihan kategori pengguna (contoh: tempatan / luar negara). Simpan nilai
                <code class="rounded bg-slate-100 px-1 py-0.5 text-[11px]">code</code>
                pada borang atau API apabila anda sambungkan medan ini.
              </p>
              <div class="flex flex-wrap gap-2 mb-2">
                <span
                  v-for="(row, idx) in lookups.userCategory"
                  :key="`uc-${idx}-${row.code}`"
                  class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700"
                >
                  <span class="font-mono text-xs text-slate-600">{{ row.code }}</span>
                  <span class="text-slate-400">·</span>
                  <span>{{ row.desc }}</span>
                  <button type="button" class="rounded p-0.5 text-slate-400 hover:bg-slate-200 hover:text-slate-600" @click="removeLookupUserCategory(idx)">×</button>
                </span>
              </div>
              <div class="flex flex-wrap items-end gap-2">
                <div class="space-y-1">
                  <label class="block text-xs font-medium text-slate-600">Code</label>
                  <input
                    v-model="lookupUserCategoryCode"
                    type="text"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm w-28 font-mono"
                    placeholder="tempatan"
                    @keydown.enter.prevent="addLookupUserCategory"
                  />
                </div>
                <div class="space-y-1 flex-1 min-w-[10rem]">
                  <label class="block text-xs font-medium text-slate-600">Description</label>
                  <input
                    v-model="lookupUserCategoryDesc"
                    type="text"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm w-full max-w-xs"
                    placeholder="user tempatan"
                    @keydown.enter.prevent="addLookupUserCategory"
                  />
                </div>
                <button type="button" class="flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="addLookupUserCategory">
                  <Plus class="h-4 w-4" />
                  Add
                </button>
              </div>
            </div>

            <div class="border-t border-slate-100 pt-4">
              <label class="mb-2 block text-sm font-medium text-slate-700">User segment (code + description)</label>
              <p class="mb-2 text-xs text-slate-500">
                Rujukan segmen pengguna (contoh: kerajaan / swasta). Simpan nilai
                <code class="rounded bg-slate-100 px-1 py-0.5 text-[11px]">code</code>
                pada borang atau API bila anda sambungkan medan ini. Lalai:
                <strong class="font-medium">1</strong> Government,
                <strong class="font-medium">2</strong> Private.
              </p>
              <div class="flex flex-wrap gap-2 mb-2">
                <span
                  v-for="(row, idx) in lookups.userSegment"
                  :key="`us-${idx}-${row.code}`"
                  class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700"
                >
                  <span class="font-mono text-xs text-slate-600">{{ row.code }}</span>
                  <span class="text-slate-400">·</span>
                  <span>{{ row.desc }}</span>
                  <button type="button" class="rounded p-0.5 text-slate-400 hover:bg-slate-200 hover:text-slate-600" @click="removeLookupUserSegment(idx)">×</button>
                </span>
              </div>
              <div class="flex flex-wrap items-end gap-2">
                <div class="space-y-1">
                  <label class="block text-xs font-medium text-slate-600">Code</label>
                  <input
                    v-model="lookupUserSegmentCode"
                    type="text"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm w-20 font-mono"
                    placeholder="1"
                    @keydown.enter.prevent="addLookupUserSegment"
                  />
                </div>
                <div class="space-y-1 flex-1 min-w-[10rem]">
                  <label class="block text-xs font-medium text-slate-600">Description</label>
                  <input
                    v-model="lookupUserSegmentDesc"
                    type="text"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm w-full max-w-xs"
                    placeholder="Government"
                    @keydown.enter.prevent="addLookupUserSegment"
                  />
                </div>
                <button type="button" class="flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="addLookupUserSegment">
                  <Plus class="h-4 w-4" />
                  Add
                </button>
              </div>
            </div>

            <div class="border-t border-slate-100 pt-4">
              <label class="mb-2 block text-sm font-medium text-slate-700">Jenis pengguna (kod + keterangan)</label>
              <p class="mb-2 text-xs text-slate-500">
                Rujukan jenis pengguna (contoh: tempatan / luar negara). Simpan nilai
                <code class="rounded bg-slate-100 px-1 py-0.5 text-[11px]">code</code>
                pada borang atau API bila anda sambungkan medan ini. Lalai:
                <strong class="font-medium">1</strong> Tempatan,
                <strong class="font-medium">2</strong> Luar negara.
              </p>
              <div class="flex flex-wrap gap-2 mb-2">
                <span
                  v-for="(row, idx) in lookups.userJenisPengguna"
                  :key="`ujp-${idx}-${row.code}`"
                  class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700"
                >
                  <span class="font-mono text-xs text-slate-600">{{ row.code }}</span>
                  <span class="text-slate-400">·</span>
                  <span>{{ row.desc }}</span>
                  <button type="button" class="rounded p-0.5 text-slate-400 hover:bg-slate-200 hover:text-slate-600" @click="removeLookupUserJenisPengguna(idx)">×</button>
                </span>
              </div>
              <div class="flex flex-wrap items-end gap-2">
                <div class="space-y-1">
                  <label class="block text-xs font-medium text-slate-600">Kod</label>
                  <input
                    v-model="lookupUserJenisPenggunaCode"
                    type="text"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm w-20 font-mono"
                    placeholder="1"
                    @keydown.enter.prevent="addLookupUserJenisPengguna"
                  />
                </div>
                <div class="space-y-1 flex-1 min-w-[10rem]">
                  <label class="block text-xs font-medium text-slate-600">Keterangan</label>
                  <input
                    v-model="lookupUserJenisPenggunaDesc"
                    type="text"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm w-full max-w-xs"
                    placeholder="Tempatan"
                    @keydown.enter.prevent="addLookupUserJenisPengguna"
                  />
                </div>
                <button type="button" class="flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="addLookupUserJenisPengguna">
                  <Plus class="h-4 w-4" />
                  Tambah
                </button>
              </div>
            </div>

            <div class="pt-2">
              <button type="button" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800" @click="saveLookups">
                Save Lookups
              </button>
            </div>
          </div>
        </article>

        <!-- ═══════ BRANDING ═══════ -->
        <article class="rounded-lg border border-slate-200 bg-white shadow-sm">
          <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
            <Image class="h-4 w-4 text-amber-600" />
            <h2 class="text-sm font-semibold text-slate-900">Branding</h2>
          </div>
          <div class="p-4">
            <div class="grid gap-3 md:grid-cols-2">
              <!-- Site Icon -->
              <div class="space-y-3">
                <label class="text-sm font-medium text-slate-700">Site Icon</label>
                <div class="flex items-start gap-4">
                  <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border-2 border-dashed border-slate-300 bg-slate-50">
                    <img v-if="form.siteIconUrl" :src="resolveUrl(form.siteIconUrl)" alt="Site icon" class="h-full w-full object-contain" />
                    <Image v-else class="h-8 w-8 text-slate-300" />
                  </div>
                  <div class="flex-1 space-y-2">
                    <div class="flex gap-2">
                      <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50">
                        <Upload class="h-4 w-4" />
                        {{ uploadingSiteIcon ? 'Uploading...' : 'Upload' }}
                        <input type="file" accept="image/*" class="hidden" @change="onSiteIconUpload" :disabled="uploadingSiteIcon" />
                      </label>
                      <button class="flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50" @click="openMediaPicker('siteIconUrl')">
                        <FolderOpen class="h-4 w-4" />
                        Library
                      </button>
                    </div>
                    <button v-if="form.siteIconUrl" class="flex items-center gap-1.5 text-xs text-slate-400 transition-colors hover:text-rose-500" @click="removeAsset('siteIconUrl', 'Site icon')">
                      <Trash2 class="h-3 w-3" />
                      Remove
                    </button>
                    <p class="text-xs text-slate-400">Recommended: 512x512px PNG</p>
                  </div>
                </div>
              </div>

              <!-- Favicon -->
              <div class="space-y-3">
                <label class="text-sm font-medium text-slate-700">Favicon</label>
                <div class="flex items-start gap-4">
                  <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border-2 border-dashed border-slate-300 bg-slate-50">
                    <img v-if="form.faviconUrl" :src="resolveUrl(form.faviconUrl)" alt="Favicon" class="h-full w-full object-contain" />
                    <Image v-else class="h-8 w-8 text-slate-300" />
                  </div>
                  <div class="flex-1 space-y-2">
                    <div class="flex gap-2">
                      <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50">
                        <Upload class="h-4 w-4" />
                        {{ uploadingFavicon ? 'Uploading...' : 'Upload' }}
                        <input type="file" accept="image/*,.ico" class="hidden" @change="onFaviconUpload" :disabled="uploadingFavicon" />
                      </label>
                      <button class="flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50" @click="openMediaPicker('faviconUrl')">
                        <FolderOpen class="h-4 w-4" />
                        Library
                      </button>
                    </div>
                    <button v-if="form.faviconUrl" class="flex items-center gap-1.5 text-xs text-slate-400 transition-colors hover:text-rose-500" @click="removeAsset('faviconUrl', 'Favicon')">
                      <Trash2 class="h-3 w-3" />
                      Remove
                    </button>
                    <p class="text-xs text-slate-400">Recommended: 32x32px ICO or PNG</p>
                  </div>
                </div>
              </div>

              <!-- Sidebar Logo -->
              <div class="space-y-3">
                <label class="text-sm font-medium text-slate-700">Sidebar Logo (Secondary)</label>
                <div class="flex items-start gap-4">
                  <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border-2 border-dashed border-slate-300 bg-slate-50">
                    <img v-if="form.sidebarLogoUrl" :src="resolveUrl(form.sidebarLogoUrl)" alt="Sidebar logo" class="h-full w-full object-contain" />
                    <Image v-else class="h-8 w-8 text-slate-300" />
                  </div>
                  <div class="flex-1 space-y-2">
                    <div class="flex gap-2">
                      <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50">
                        <Upload class="h-4 w-4" />
                        {{ uploadingSidebarLogo ? 'Uploading...' : 'Upload' }}
                        <input type="file" accept="image/*" class="hidden" @change="onSidebarLogoUpload" :disabled="uploadingSidebarLogo" />
                      </label>
                      <button class="flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50" @click="openMediaPicker('sidebarLogoUrl')">
                        <FolderOpen class="h-4 w-4" />
                        Library
                      </button>
                    </div>
                    <button v-if="form.sidebarLogoUrl" class="flex items-center gap-1.5 text-xs text-slate-400 transition-colors hover:text-rose-500" @click="removeAsset('sidebarLogoUrl', 'Sidebar logo')">
                      <Trash2 class="h-3 w-3" />
                      Remove
                    </button>
                    <p class="text-xs text-slate-400">Shown at top of sidebar when provided.</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </article>

        <!-- ═══════ ACTIONS ═══════ -->
        <div class="space-y-3">
          <div class="flex items-center gap-3">
            <button
              class="flex items-center gap-2 rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-slate-800 disabled:opacity-50"
              :disabled="saving"
              @click="save"
            >
              <Save class="h-4 w-4" />
              {{ saving ? 'Saving...' : 'Save Settings' }}
            </button>
            <Transition
              enter-active-class="transition duration-200 ease-out"
              enter-from-class="translate-y-1 opacity-0"
              enter-to-class="translate-y-0 opacity-100"
              leave-active-class="transition duration-150 ease-in"
              leave-from-class="opacity-100"
              leave-to-class="opacity-0"
            >
              <span v-if="saved" class="flex items-center gap-1.5 text-sm font-medium text-emerald-600">
                <CheckCircle2 class="h-4 w-4" />
                Saved
              </span>
            </Transition>
          </div>
          <p v-if="error" class="text-sm text-rose-600">{{ error }}</p>
        </div>
      </div>
    </div>

    <!-- ═══════ MEDIA PICKER MODAL ═══════ -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div v-if="mediaPickerOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm" @click.self="mediaPickerOpen = false">
          <div class="mx-4 flex max-h-[80vh] w-full max-w-2xl flex-col rounded-xl border border-slate-200 bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
              <div class="flex items-center gap-2">
                <FolderOpen class="h-4 w-4 text-amber-600" />
                <h3 class="text-sm font-semibold text-slate-900">Select from Library</h3>
              </div>
              <button class="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600" @click="mediaPickerOpen = false">
                <X class="h-4 w-4" />
              </button>
            </div>
            <div class="flex-1 overflow-y-auto p-4">
              <p v-if="mediaPickerLoading" class="py-10 text-center text-sm text-slate-400">Loading...</p>
              <p v-else-if="mediaPickerItems.length === 0" class="py-10 text-center text-sm text-slate-400">No images in library.</p>
              <div v-else class="grid grid-cols-4 gap-3 sm:grid-cols-5">
                <button
                  v-for="item in mediaPickerItems"
                  :key="item.id"
                  class="group relative aspect-square overflow-hidden rounded-lg border border-slate-200 bg-slate-100 transition-all hover:border-[var(--accent-400)] hover:ring-1 hover:ring-[var(--accent-200)]"
                  @click="selectFromLibrary(item)"
                >
                  <img :src="resolveUrl(item.url)" :alt="item.altText || item.originalName" class="absolute inset-0 h-full w-full object-cover" />
                </button>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </AdminLayout>
</template>
