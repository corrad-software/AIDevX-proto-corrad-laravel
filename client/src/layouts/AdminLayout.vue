<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
  Bell,
  Check,
  ChevronDown,
  LogOut,
  Monitor,
  Moon,
  MoreHorizontal,
  Settings,
  Sun,
  UserCog,
  X,
} from "lucide-vue-next";

import type { ThemeAppearance, ThemeColor } from "@/types";
import type { MenuItemDef, MenuNode } from "@/config/admin-menu";
import { useSidebarCollapse } from "@/composables/useSidebarCollapse";
import { useToast } from "@/composables/useToast";
import AppToastRegion from "@/components/AppToastRegion.vue";

import { impersonateUser, stopImpersonate, getImpersonateUsers } from "@/api/auth";
import { useAuthStore } from "@/stores/auth";
import { useMenuStore } from "@/stores/menu";
import { useSiteStore } from "@/stores/site";
import { useUiThemeStore } from "@/stores/uiTheme";
import { API_BASE_URL } from "@/env";
import { ensureCsrfCookie } from "@/api/client";
import * as BRANDING from "@/config/branding";
import { getUnreadNotificationCount, listMyNotifications, markNotificationsRead, listUsers } from "@/api/cms";
import type { InAppNotification } from "@/types";

/** Masa build bundle `public/spa` (kosong semasa `npm run dev`). */
const adminBuildStamp = __VITE_ADMIN_BUILD__;

const props = withDefaults(
  defineProps<{
    /** Full-viewport shell only (no header/sidebar) — for popup embed from MYFIS2 etc. */
    embedMode?: boolean;
  }>(),
  { embedMode: false },
);

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const menuStore = useMenuStore();
const site = useSiteStore();
const uiTheme = useUiThemeStore();
const toast = useToast();
const { isCollapsed, isCompact, toggle: toggleSidebar, toggleCompact } = useSidebarCollapse();

const settingsOpen = ref(false);
const settingsDropdownRef = ref<HTMLElement | null>(null);
const impersonateOpen = ref(false);
const impersonateDropdownRef = ref<HTMLElement | null>(null);
const impersonateUsers = ref<{ id: number; name: string; email: string }[]>([]);
const impersonateSearch = ref("");
const impersonateLoading = ref(false);
const notifOpen = ref(false);
const notifDropdownRef = ref<HTMLElement | null>(null);
const unreadCount = ref(0);
const notifPreview = ref<InAppNotification[]>([]);
let unreadPollTimer: ReturnType<typeof setInterval> | null = null;

const themeChoices: Array<{ label: string; value: ThemeColor }> = [
  { label: "Violet", value: "violet" },
  { label: "Blue", value: "blue" },
  { label: "Green", value: "green" },
  { label: "Red", value: "red" },
  { label: "B&W", value: "black-white" },
  { label: "Grey", value: "grey" },
];

const appearanceChoices: Array<{ label: string; value: ThemeAppearance; icon: typeof Sun }> = [
  { label: "Light", value: "light" as ThemeAppearance, icon: Sun },
  { label: "Dark", value: "dark" as ThemeAppearance, icon: Moon },
  { label: "System", value: "system" as ThemeAppearance, icon: Monitor },
];

const handleDocumentClick = (event: MouseEvent) => {
  const target = event.target as Node;
  if (settingsOpen.value && settingsDropdownRef.value && !settingsDropdownRef.value.contains(target)) {
    settingsOpen.value = false;
  }
  if (impersonateOpen.value && impersonateDropdownRef.value && !impersonateDropdownRef.value.contains(target)) {
    impersonateOpen.value = false;
  }
  if (notifOpen.value && notifDropdownRef.value && !notifDropdownRef.value.contains(target)) {
    notifOpen.value = false;
  }
};

const handleEscape = (event: KeyboardEvent) => {
  if (event.key === "Escape") {
    settingsOpen.value = false;
    impersonateOpen.value = false;
    notifOpen.value = false;
  }
};

function resolveUrl(url: string) {
  if (!url) return "";
  if (url.startsWith("http")) return url;
  return `${API_BASE_URL}${url}`;
}

onMounted(() => {
  site.load();
  menuStore.load();
  document.addEventListener("click", handleDocumentClick);
  document.addEventListener("keydown", handleEscape);
  refreshUnreadCount();
  unreadPollTimer = window.setInterval(refreshUnreadCount, 120_000);
});

onBeforeUnmount(() => {
  document.removeEventListener("click", handleDocumentClick);
  document.removeEventListener("keydown", handleEscape);
  if (showTitleTimer !== null) {
    window.clearTimeout(showTitleTimer);
    showTitleTimer = null;
  }
  if (unreadPollTimer !== null) {
    window.clearInterval(unreadPollTimer);
    unreadPollTimer = null;
  }
});

const openMenus = reactive<Record<string, boolean>>({});

const userInitials = computed(() => {
  if (!auth.user?.name) return "A";
  return auth.user.name
    .split(" ")
    .map((n) => n[0])
    .join("")
    .toUpperCase()
    .slice(0, 2);
});

const USER_LEVEL_LABELS: Record<string, string> = {
  super_admin: "L0 Super Admin",
  internal_admin: "L1 Internal",
  external_admin: "L2 External",
  agent: "L3 Agent",
  user: "L4 User",
  secondary_user: "L5 User+",
};
const userRoleLabel = computed(() => {
  const level = auth.user?.userLevel;
  if (level && USER_LEVEL_LABELS[level]) return USER_LEVEL_LABELS[level];
  return auth.user?.role || "Administrator";
});
const HEADER_TEXT_MAX = 20;

function truncateHeaderText(value: string, max = HEADER_TEXT_MAX) {
  if (!value) return "";
  return value.length > max ? `${value.slice(0, max)}...` : value;
}

const headerSiteTitle = computed(() => truncateHeaderText(site.siteTitle || ""));
const headerUserName = computed(() => truncateHeaderText(auth.user?.name || "Admin"));
const headerUserRole = computed(() => truncateHeaderText(userRoleLabel.value));
const isImpersonating = computed(() => Boolean(auth.user?.impersonating));
const canImpersonate = computed(
  () =>
    !isImpersonating.value &&
    (() => {
      const l = auth.user?.userLevel ?? "";
      return (
        l === "super_admin" ||
        l === "internal_admin" ||
        l === "external_admin" ||
        l === "agent"
      );
    })(),
);

function isUnauthenticatedError(e: unknown): boolean {
  const m = e instanceof Error ? e.message : String(e);
  return /unauthenticated/i.test(m);
}

async function refreshUnreadCount() {
  if (!auth.isAuthenticated || props.embedMode) return;
  try {
    const r = await getUnreadNotificationCount();
    unreadCount.value = r.data.count;
  } catch (e) {
    unreadCount.value = 0;
    // Background poll: clear stale client session only; avoid redirect while user is mid-task.
    if (isUnauthenticatedError(e)) {
      auth.clearStaleSession();
    }
  }
}

async function loadNotifPreview() {
  try {
    await ensureCsrfCookie();
    const r = await listMyNotifications("?limit=8&unread_only=true");
    notifPreview.value = r.data;
  } catch (e) {
    notifPreview.value = [];
    if (isUnauthenticatedError(e)) {
      auth.clearStaleSession();
      void router.push({ name: "login", query: { redirect: route.fullPath } });
    }
  }
}

watch(notifOpen, (open) => {
  if (open) loadNotifPreview();
});

watch(
  () => auth.user?.id,
  () => {
    refreshUnreadCount();
  },
);

async function onClickNotifItem(n: InAppNotification) {
  if (!n.readAt) {
    try {
      await ensureCsrfCookie();
      await markNotificationsRead([n.id]);
      n.readAt = new Date().toISOString();
      await refreshUnreadCount();
    } catch (e) {
      if (isUnauthenticatedError(e)) {
        auth.clearStaleSession();
        void router.push({ name: "login", query: { redirect: route.fullPath } });
      }
    }
  }
  notifOpen.value = false;
}

async function loadImpersonateUsers() {
  impersonateLoading.value = true;
  try {
    const res = await getImpersonateUsers(impersonateSearch.value || undefined);
    impersonateUsers.value = res.data;
    if (impersonateUsers.value.length === 0) {
      const q = (impersonateSearch.value || "").trim();
      const fallbackParams = q ? `?page=1&limit=50&q=${encodeURIComponent(q)}` : "?page=1&limit=50";
      const fallback = await listUsers(fallbackParams);
      impersonateUsers.value = (fallback.data || [])
        .filter((u) => u.id !== auth.user?.id)
        .map((u) => ({ id: u.id, name: u.name, email: u.email }));
    }
  } catch {
    try {
      const q = (impersonateSearch.value || "").trim();
      const fallbackParams = q ? `?page=1&limit=50&q=${encodeURIComponent(q)}` : "?page=1&limit=50";
      const fallback = await listUsers(fallbackParams);
      impersonateUsers.value = (fallback.data || [])
        .filter((u) => u.id !== auth.user?.id)
        .map((u) => ({ id: u.id, name: u.name, email: u.email }));
    } catch {
      impersonateUsers.value = [];
    }
  } finally {
    impersonateLoading.value = false;
  }
}

watch(impersonateOpen, (open) => {
  if (open) {
    impersonateSearch.value = "";
    loadImpersonateUsers();
  }
});

let searchDebounce: ReturnType<typeof setTimeout> | null = null;
watch(impersonateSearch, () => {
  if (!impersonateOpen.value) return;
  if (searchDebounce) clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => loadImpersonateUsers(), 300);
});

async function doImpersonate(userId: number) {
  try {
    const res = await impersonateUser(userId);
    auth.user = { ...res.data.user, impersonating: true, impersonatedBy: res.data.impersonatedBy };
    impersonateOpen.value = false;
    toast.success("Impersonating", `Now viewing as ${res.data.user.name}`);
  } catch (e) {
    toast.error("Impersonate failed", e instanceof Error ? e.message : "Please try again.");
  }
}

async function doStopImpersonate() {
  try {
    const res = await stopImpersonate();
    auth.user = res.data.user;
    toast.success("Stopped", "Back to your account.");
  } catch (e) {
    toast.error("Stop failed", e instanceof Error ? e.message : "Please try again.");
  }
}
const hasActiveToast = computed(() => toast.toasts.value.length > 0);
const showSiteTitle = ref(true);
const TOAST_EXIT_MS = 1500;
let showTitleTimer: number | null = null;

const rowBaseClass = computed(() =>
  isCompact.value
    ? "gap-2.5 px-3 py-1 text-[13px] leading-tight"
    : "gap-2.5 px-3 py-1.5 text-sm",
);

const collapsedRowBaseClass = computed(() =>
  isCompact.value
    ? "md:justify-center md:px-0 md:py-1.5 md:rounded-none gap-2.5 px-3 py-1"
    : "md:justify-center md:px-0 md:py-2.5 md:rounded-none gap-2.5 px-3 py-1.5",
);

const childRowClass = computed(() =>
  isCompact.value
    ? "block rounded-md px-3 py-0.5 text-[13px] leading-tight transition-all hover:bg-[var(--accent-50)] dark:hover:bg-slate-800/80"
    : "block rounded-md px-3 py-1 text-sm transition-all hover:bg-[var(--accent-50)] dark:hover:bg-slate-800/80",
);

async function signOut() {
  try {
    await auth.signOut();
    toast.success("Signed out", "You have been logged out.");
    router.push("/admin/login");
  } catch (e) {
    toast.error("Sign out failed", e instanceof Error ? e.message : "Please try again.");
  }
}

function isActive(path: string): boolean {
  if (path === "/") return route.path === "/";
  return route.path.startsWith(path);
}

function itemClass(path: string) {
  if (isActive(path)) {
    return "border border-[var(--accent-200)] bg-[var(--accent-50)] font-medium text-[var(--accent-700)] dark:border-[var(--accent-600)]/35 dark:bg-[var(--accent-700)]/15 dark:text-[var(--accent-200)]";
  }
  return "border border-transparent text-slate-900 dark:text-slate-200";
}

function childClass(path: string) {
  if (route.path === path) {
    return "border border-[var(--accent-200)] bg-[var(--accent-50)] font-medium text-[var(--accent-700)] dark:border-[var(--accent-600)]/35 dark:bg-[var(--accent-700)]/15 dark:text-[var(--accent-200)]";
  }
  return "border border-transparent text-slate-600 dark:text-slate-400";
}

function toggleMenu(id: string) {
  openMenus[id] = !openMenus[id];
}

function isNodeActive(node: { to: string; children?: MenuNode[] }): boolean {
  if (isActive(node.to)) return true;
  if (!node.children || node.children.length === 0) return false;
  return node.children.some((child) => isNodeActive(child));
}

function syncOpenMenus() {
  const syncNode = (node: MenuNode | MenuItemDef) => {
    if (node.children && node.children.length > 0 && isNodeActive(node)) {
      openMenus[node.id] = true;
      for (const child of node.children) syncNode(child);
    }
  };

  for (const group of menuStore.resolvedMenu) {
    for (const item of group.items) {
      syncNode(item);
    }
  }
}

watch(() => route.path, syncOpenMenus, { immediate: true });
watch(() => menuStore.resolvedMenu, syncOpenMenus, { deep: true });
watch(
  hasActiveToast,
  (active) => {
    if (showTitleTimer !== null) {
      window.clearTimeout(showTitleTimer);
      showTitleTimer = null;
    }
    if (active) {
      showSiteTitle.value = false;
      return;
    }
    showTitleTimer = window.setTimeout(() => {
      showSiteTitle.value = true;
      showTitleTimer = null;
    }, TOAST_EXIT_MS);
  },
  { immediate: true },
);
</script>

<template>
  <div class="min-h-screen bg-[#f8f9fb] dark:bg-slate-950">
    <!-- Embed: chat-only full viewport (popup / external integration) -->
    <main
      v-if="embedMode"
      class="fixed inset-0 z-50 h-[100dvh] w-full overflow-hidden bg-gray-50 p-0 dark:bg-slate-950"
    >
      <slot />
    </main>

    <template v-else>
    <header
      class="sticky top-0 z-40 flex h-10 items-center justify-between border-b border-slate-200 bg-white px-5 dark:border-slate-800 dark:bg-slate-900"
    >
      <div class="flex flex-col justify-center leading-tight">
        <span class="text-sm font-semibold tracking-tight text-slate-800 dark:text-slate-100">{{ BRANDING.PLATFORM_HEADER }}</span>
        <span class="hidden text-[10px] font-medium text-slate-500 dark:text-slate-400 sm:block">{{ BRANDING.PLATFORM_SUBTITLE }}</span>
      </div>

      <div class="flex items-center self-stretch">
        <AppToastRegion />

        <!-- Impersonate (super_admin only) -->
        <div v-if="canImpersonate" ref="impersonateDropdownRef" class="relative flex h-full items-stretch">
          <button
            class="flex h-full items-center gap-1.5 px-3 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200"
            @click="impersonateOpen = !impersonateOpen"
          >
            <UserCog class="h-4 w-4" />
            <span class="text-xs font-medium">Impersonate</span>
            <ChevronDown class="h-3 w-3" :class="impersonateOpen && 'rotate-180'" />
          </button>
          <div
            v-if="impersonateOpen"
            class="absolute right-0 top-full z-50 mt-1 w-72 rounded-lg border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900"
          >
            <div class="border-b border-slate-100 p-2 dark:border-slate-800">
              <input
                v-model="impersonateSearch"
                type="text"
                placeholder="Search user..."
                class="w-full rounded border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-900 focus:border-[var(--accent-500)] focus:outline-none dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
              />
            </div>
            <div class="max-h-60 overflow-y-auto p-1">
              <div v-if="impersonateLoading" class="py-4 text-center text-xs text-slate-500">Loading...</div>
              <button
                v-else-if="impersonateUsers.length === 0"
                class="w-full px-3 py-2 text-left text-xs text-slate-500"
              >
                No users found
              </button>
              <button
                v-else
                v-for="u in impersonateUsers"
                :key="u.id"
                class="flex w-full flex-col gap-0.5 rounded px-3 py-2 text-left text-sm transition-colors hover:bg-slate-50 dark:hover:bg-slate-800"
                @click="doImpersonate(u.id)"
              >
                <span class="font-medium text-slate-800 dark:text-slate-100">{{ u.name }}</span>
                <span class="text-xs text-slate-500">{{ u.email }}</span>
              </button>
            </div>
          </div>
        </div>

        <!-- Stop impersonating -->
        <button
          v-if="isImpersonating"
          class="flex h-full items-center gap-1.5 rounded-sm bg-amber-100 px-3 text-amber-800 transition-colors hover:bg-amber-200"
          @click="doStopImpersonate"
        >
          <X class="h-3.5 w-3.5" />
          <span class="text-xs font-medium">Stop impersonating</span>
        </button>

        <span v-if="canImpersonate || isImpersonating" class="h-full w-px bg-slate-200 dark:bg-slate-700" />

        <router-link
          :to="'/admin/settings/users/' + auth.user?.id"
          class="group relative flex h-full items-center gap-2 px-4 transition-colors hover:bg-[var(--accent-600)] dark:hover:bg-[var(--accent-600)]"
        >
          <div
            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-[var(--accent-600)] to-[var(--accent-500)] text-[10px] font-semibold text-white"
          >
            {{ userInitials }}
          </div>
          <div class="leading-tight">
            <p class="text-sm font-medium text-slate-700 group-hover:text-white dark:text-slate-200">{{ headerUserName }}</p>
            <p class="text-[11px] text-slate-500 group-hover:text-white/80 dark:text-slate-400">{{ headerUserRole }}</p>
          </div>
          <span class="pointer-events-none absolute -bottom-8 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100">Profile</span>
        </router-link>

        <span class="h-full w-px bg-slate-200 dark:bg-slate-700" />

        <div ref="settingsDropdownRef" class="relative flex h-full items-stretch">
          <button
            class="group relative flex h-full items-center px-4 text-slate-500 transition-colors hover:bg-[var(--accent-600)] hover:text-white dark:text-slate-400"
            @click.stop="settingsOpen = !settingsOpen"
          >
            <Settings class="h-4 w-4" />
            <span class="pointer-events-none absolute -bottom-8 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100">Theme settings</span>
          </button>

          <div
            v-if="settingsOpen"
            class="absolute right-0 top-full z-50 mt-2 w-64 rounded-xl border border-slate-200 bg-white p-3 shadow-xl dark:border-slate-700 dark:bg-slate-900"
          >
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Appearance</p>
            <div class="mb-3 flex gap-1 rounded-lg bg-slate-100 p-1 dark:bg-slate-800">
              <button
                v-for="opt in appearanceChoices"
                :key="opt.value"
                type="button"
                class="flex flex-1 flex-col items-center gap-0.5 rounded-md py-2 text-[10px] font-medium transition-colors"
                :class="uiTheme.appearance === opt.value
                  ? 'bg-white text-[var(--accent-700)] shadow dark:bg-slate-950 dark:text-[var(--accent-300)]'
                  : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-200'"
                @click="uiTheme.setAppearance(opt.value)"
              >
                <component :is="opt.icon" class="h-3.5 w-3.5" />
                {{ opt.label }}
              </button>
            </div>
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Accent color</p>
            <div class="grid grid-cols-2 gap-2">
              <button
                v-for="theme in themeChoices"
                :key="theme.value"
                class="flex items-center justify-between rounded-md border px-2.5 py-2 text-xs font-medium transition-colors"
                :class="uiTheme.themeColor === theme.value
                  ? 'border-[var(--accent-500)] bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-[var(--accent-700)]/20 dark:text-[var(--accent-200)]'
                  : 'border-slate-200 text-slate-600 hover:border-[var(--accent-ring)] hover:text-slate-900 dark:border-slate-600 dark:text-slate-400 dark:hover:text-slate-100'"
                @click="uiTheme.setThemeColor(theme.value)"
              >
                <span class="flex items-center gap-2">
                  <span
                    class="h-2.5 w-2.5 rounded-full"
                    :class="theme.value === 'violet'
                      ? 'bg-violet-500'
                      : theme.value === 'blue'
                        ? 'bg-blue-500'
                        : theme.value === 'green'
                          ? 'bg-emerald-500'
                          : theme.value === 'red'
                            ? 'bg-rose-500'
                            : theme.value === 'black-white'
                              ? 'bg-slate-900'
                              : 'bg-neutral-500'"
                  />
                  {{ theme.label }}
                </span>
                <Check v-if="uiTheme.themeColor === theme.value" class="h-3.5 w-3.5" />
              </button>
            </div>

            <div class="mt-3 border-t border-slate-200 pt-3 dark:border-slate-700">
              <button
                class="flex w-full items-center justify-between rounded-md border border-slate-200 px-2.5 py-2 text-xs font-medium text-slate-700 transition-colors hover:border-[var(--accent-ring)] dark:border-slate-600 dark:text-slate-300"
                @click="toggleCompact"
              >
                <span>Compact sidebar</span>
                <span
                  class="relative inline-flex h-4 w-7 items-center rounded-full transition-colors"
                  :class="isCompact ? 'bg-[var(--accent-600)]' : 'bg-slate-300'"
                >
                  <span
                    class="inline-block h-3 w-3 transform rounded-full bg-white transition"
                    :class="isCompact ? 'translate-x-3.5' : 'translate-x-0.5'"
                  />
                </span>
              </button>
            </div>
          </div>
        </div>

        <span class="h-full w-px bg-slate-200 dark:bg-slate-700" />

        <div ref="notifDropdownRef" class="relative flex h-full items-stretch">
          <button
            type="button"
            class="group relative flex h-full items-center px-4 text-slate-500 transition-colors hover:bg-[var(--accent-600)] hover:text-white dark:text-slate-400"
            @click.stop="notifOpen = !notifOpen"
          >
            <Bell class="h-4 w-4" />
            <span
              v-if="unreadCount > 0"
              class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-rose-500 ring-2 ring-white dark:ring-slate-900"
            />
            <span class="pointer-events-none absolute -bottom-8 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100">Notifications</span>
          </button>
          <div
            v-if="notifOpen"
            class="absolute right-0 top-full z-50 mt-1 w-80 max-w-[calc(100vw-2rem)] rounded-lg border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900"
          >
            <div class="flex items-center justify-between border-b border-slate-100 px-3 py-2 dark:border-slate-800">
              <span class="text-xs font-semibold text-slate-700 dark:text-slate-200">Unread</span>
              <router-link
                to="/admin/kerisi/notifications"
                class="inline-flex items-center gap-1 rounded p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-slate-100"
                title="All notifications"
                @click="notifOpen = false"
              >
                <MoreHorizontal class="h-4 w-4" />
              </router-link>
            </div>
            <div class="max-h-72 overflow-y-auto">
              <div v-if="!notifPreview.length" class="px-3 py-6 text-center text-xs text-slate-500">No unread notifications</div>
              <button
                v-for="n in notifPreview"
                :key="n.id"
                type="button"
                class="block w-full border-b border-slate-50 px-3 py-2.5 text-left text-xs transition-colors hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/80"
                @click="onClickNotifItem(n)"
              >
                <span class="font-medium text-slate-800 dark:text-slate-100">{{ n.title }}</span>
                <span v-if="n.body" class="mt-0.5 line-clamp-2 block text-slate-500 dark:text-slate-400">{{ n.body }}</span>
              </button>
            </div>
            <div class="border-t border-slate-100 p-2 dark:border-slate-800">
              <router-link
                to="/admin/kerisi/notifications"
                class="block w-full rounded-md py-2 text-center text-xs font-medium text-[var(--accent-600)] hover:bg-[var(--accent-50)] dark:hover:bg-slate-800"
                @click="notifOpen = false"
              >
                View all notifications
              </router-link>
              <router-link
                v-if="auth.user?.permissions?.includes('notifications.admin')"
                to="/admin/platform/notifications"
                class="mt-1 block w-full rounded-md py-1.5 text-center text-[11px] text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200"
                @click="notifOpen = false"
              >
                Admin: notification center
              </router-link>
            </div>
          </div>
        </div>

        <span class="h-full w-px bg-slate-200 dark:bg-slate-700" />

        <button
          class="group relative flex h-full items-center px-4 text-slate-500 transition-colors hover:bg-[var(--accent-600)] hover:text-white dark:text-slate-400"
          @click="signOut"
        >
          <LogOut class="h-4 w-4" />
          <span class="pointer-events-none absolute -bottom-8 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100">Logout</span>
        </button>
      </div>
    </header>

    <div class="flex flex-col md:flex-row">
      <aside
        class="relative flex flex-col border-r border-slate-200 bg-slate-50/50 transition-[width] duration-300 ease-in-out md:min-h-[calc(100vh-40px)]"
        :class="isCollapsed ? 'w-full md:w-14' : 'w-full md:w-64'"
      >
        <button
          class="absolute -right-3.5 top-10 z-40 hidden h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-[var(--accent-600)] text-white shadow-md transition-all hover:bg-[var(--accent-700)] hover:shadow-lg md:flex"
          :title="isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
          @click="toggleSidebar"
        >
          <ChevronDown
            class="h-4 w-4 transition-transform duration-200"
            :class="isCollapsed ? '-rotate-90' : 'rotate-90'"
          />
        </button>

        <div
          v-if="site.sidebarLogoUrl"
          class="border-b border-slate-200 bg-white px-3 py-3 dark:border-slate-800 dark:bg-slate-900"
          :class="isCollapsed ? 'md:hidden' : ''"
        >
          <div class="flex h-12 items-center justify-center overflow-hidden">
            <img :src="resolveUrl(site.sidebarLogoUrl)" alt="Sidebar logo" class="h-full w-full object-contain" />
          </div>
        </div>

        <nav class="flex-1 p-3" :class="isCollapsed ? 'md:overflow-visible md:px-0 md:py-2' : ''">
          <div v-for="(group, gi) in menuStore.resolvedMenu" :key="group.id">
            <p
              v-if="group.label"
              class="px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500"
              :class="[gi === 0 ? 'mb-1' : 'mb-1 mt-4', isCollapsed ? 'md:hidden' : '']"
            >
              {{ group.label }}
            </p>

            <div v-for="item in group.items" :key="item.id" class="mb-0.5">
              <button
                v-if="item.children && item.children.length > 0"
                type="button"
                class="group relative flex w-full items-center rounded-lg text-left font-medium transition-all hover:bg-[var(--accent-50)]"
                :class="[
                  isCollapsed ? collapsedRowBaseClass : rowBaseClass,
                  isCollapsed && isNodeActive(item) ? 'md:border md:border-[var(--accent-200)] md:bg-[var(--accent-50)] md:text-[var(--accent-700)] md:font-medium text-slate-900' : 'text-slate-900',
                  isCollapsed ? '' : itemClass(isNodeActive(item) ? route.path : item.to)
                ]"
                @click="isCollapsed ? toggleSidebar() : toggleMenu(item.id)"
              >
                <component
                  :is="item.icon"
                  class="shrink-0 transition-colors"
                  :class="[
                    isCollapsed ? 'md:h-5 md:w-5 h-4 w-4' : 'h-4 w-4',
                    isCollapsed && isNodeActive(item) ? 'md:text-[var(--accent-700)] text-slate-700' : isNodeActive(item) ? 'text-slate-900' : 'text-slate-400 group-hover:text-[var(--accent-600)]'
                  ]"
                />
                <span class="flex-1" :class="isCollapsed ? 'md:hidden' : ''">{{ item.label }}</span>
                <ChevronDown
                  class="h-4 w-4 text-slate-400 transition-transform duration-200"
                  :class="[{ '-rotate-90': !openMenus[item.id] }, isCollapsed ? 'md:hidden' : '']"
                />
                <span
                  v-if="isCollapsed"
                  class="pointer-events-none absolute left-full z-50 ml-2 hidden whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100 md:block"
                >
                  {{ item.label }}
                </span>
              </button>

              <router-link
                v-else
                :to="item.to"
                class="group relative flex items-center rounded-lg font-medium transition-all hover:bg-[var(--accent-50)]"
                :class="[
                  isCollapsed ? collapsedRowBaseClass : rowBaseClass,
                  isCollapsed && isActive(item.to) ? 'md:border md:border-[var(--accent-200)] md:bg-[var(--accent-50)] md:text-[var(--accent-700)] md:font-medium text-slate-900' : 'text-slate-900',
                  isCollapsed ? '' : itemClass(item.to)
                ]"
              >
                <component
                  :is="item.icon"
                  class="shrink-0 transition-colors"
                  :class="[
                    isCollapsed ? 'md:h-5 md:w-5 h-4 w-4' : 'h-4 w-4',
                    isCollapsed && isActive(item.to) ? 'md:text-[var(--accent-700)] text-slate-700' : isActive(item.to) ? 'text-slate-900' : 'text-slate-400 group-hover:text-[var(--accent-600)]'
                  ]"
                />
                <span class="flex-1" :class="isCollapsed ? 'md:hidden' : ''">{{ item.label }}</span>
                <span
                  v-if="isCollapsed"
                  class="pointer-events-none absolute left-full z-50 ml-2 hidden whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100 md:block"
                >
                  {{ item.label }}
                </span>
              </router-link>

              <div
                v-if="item.children && item.children.length > 0 && openMenus[item.id] && !isCollapsed"
                class="ml-5 mt-1 space-y-0.5 border-l-2 border-slate-200 pl-4 dark:border-slate-700"
              >
                <template v-for="child in item.children" :key="child.id">
                  <button
                    v-if="child.children && child.children.length > 0"
                    type="button"
                    class="flex w-full items-center rounded-md text-left transition-all hover:bg-[var(--accent-50)]"
                    :class="[childRowClass, childClass(isNodeActive(child) ? route.path : child.to)]"
                    @click="toggleMenu(child.id)"
                  >
                    <span class="flex-1">{{ child.label }}</span>
                    <ChevronDown
                      class="h-3.5 w-3.5 text-slate-400 transition-transform duration-200"
                      :class="{ '-rotate-90': !openMenus[child.id] }"
                    />
                  </button>

                  <router-link
                    v-else
                    :to="child.to"
                    :class="[childRowClass, childClass(child.to)]"
                  >
                    {{ child.label }}
                  </router-link>

                  <div
                    v-if="child.children && child.children.length > 0 && openMenus[child.id]"
                    class="ml-4 mt-1 space-y-0.5 border-l border-slate-200 pl-3 dark:border-slate-700"
                  >
                    <router-link
                      v-for="grandchild in child.children"
                      :key="grandchild.id"
                      :to="grandchild.to"
                      :class="[childRowClass, childClass(grandchild.to)]"
                    >
                      {{ grandchild.label }}
                    </router-link>
                  </div>
                </template>
              </div>
            </div>
          </div>
        </nav>

        <div
          v-if="site.footerText"
          class="border-t border-slate-200 px-3 py-2.5 transition-opacity duration-300 dark:border-slate-800"
          :class="isCollapsed ? 'md:hidden' : ''"
        >
          <p class="text-[11px] leading-relaxed text-slate-400 dark:text-slate-500">{{ site.footerText }}</p>
        </div>

        <div
          v-if="adminBuildStamp"
          class="border-t border-slate-200 px-3 py-1.5 dark:border-slate-800"
          :class="isCollapsed ? 'md:hidden' : ''"
          title="Masa `npm run build:laravel` terakhir — jika tidak berubah, bundle mungkin belum dibina semula."
        >
          <p class="font-mono text-[10px] leading-tight text-slate-400/90 dark:text-slate-500">
            SPA: {{ adminBuildStamp }}
          </p>
        </div>
      </aside>

      <main
        class="w-full min-w-0 flex-1 bg-white p-3 transition-all duration-300 ease-in-out dark:bg-slate-950 md:p-4"
      >
        <slot />
      </main>
    </div>
    </template>
  </div>
</template>
