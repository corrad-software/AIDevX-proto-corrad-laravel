<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { Bell, CheckCheck } from "lucide-vue-next";
import AdminLayout from "@/layouts/AdminLayout.vue";
import { ensureCsrfCookie } from "@/api/client";
import { listMyNotifications, markAllNotificationsRead, markNotificationsRead } from "@/api/cms";
import type { InAppNotification } from "@/types";
import { useToast } from "@/composables/useToast";
import { useAuthStore } from "@/stores/auth";

const toast = useToast();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const rows = ref<InAppNotification[]>([]);
const loading = ref(true);
const unreadOnly = ref(false);

function isUnauthenticatedError(e: unknown): boolean {
  const m = e instanceof Error ? e.message : String(e);
  return /unauthenticated/i.test(m);
}

async function load() {
  loading.value = true;
  try {
    await ensureCsrfCookie();
    const q = unreadOnly.value ? "?limit=50&unread_only=true" : "?limit=50";
    const res = await listMyNotifications(q);
    rows.value = res.data;
  } catch (e) {
    rows.value = [];
    if (isUnauthenticatedError(e)) {
      auth.clearStaleSession();
      await router.push({ name: "login", query: { redirect: route.fullPath } });
      return;
    }
    toast.error(e instanceof Error ? e.message : "Failed to load");
  } finally {
    loading.value = false;
  }
}

async function markOne(n: InAppNotification) {
  if (n.readAt) return;
  try {
    await ensureCsrfCookie();
    await markNotificationsRead([n.id]);
    n.readAt = new Date().toISOString();
    toast.success("Marked read");
  } catch (e) {
    if (isUnauthenticatedError(e)) {
      auth.clearStaleSession();
      await router.push({ name: "login", query: { redirect: route.fullPath } });
      return;
    }
    toast.error("Could not update");
  }
}

async function markAll() {
  try {
    await ensureCsrfCookie();
    await markAllNotificationsRead();
    await load();
    toast.success("All marked read");
  } catch (e) {
    if (isUnauthenticatedError(e)) {
      auth.clearStaleSession();
      await router.push({ name: "login", query: { redirect: route.fullPath } });
      return;
    }
    toast.error("Failed");
  }
}

onMounted(load);
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-4xl px-4 py-6">
      <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-slate-100">
          <Bell class="h-6 w-6 text-[var(--accent-600)]" />
          Notifications
        </h1>
        <div class="flex flex-wrap items-center gap-2">
          <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
            <input v-model="unreadOnly" type="checkbox" class="rounded border-slate-300" @change="load" />
            Unread only
          </label>
          <button
            type="button"
            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200"
            @click="markAll"
          >
            <CheckCheck class="h-4 w-4" />
            Mark all read
          </button>
        </div>
      </div>

      <div v-if="loading" class="py-12 text-center text-slate-500">Loading…</div>
      <ul v-else class="space-y-2">
        <li
          v-for="n in rows"
          :key="n.id"
          class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900"
          :class="{ 'ring-1 ring-[var(--accent-400)]': !n.readAt }"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="font-medium text-slate-900 dark:text-slate-100">{{ n.title }}</p>
              <p v-if="n.body" class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ n.body }}</p>
              <p class="mt-2 text-xs text-slate-400">
                {{ n.notificationType }}<span v-if="n.module"> · {{ n.module }}</span>
                · {{ new Date(n.createdAt).toLocaleString() }}
              </p>
            </div>
            <button
              v-if="!n.readAt"
              type="button"
              class="shrink-0 text-xs font-medium text-[var(--accent-600)] hover:underline"
              @click="markOne(n)"
            >
              Mark read
            </button>
          </div>
        </li>
        <li v-if="!rows.length" class="py-8 text-center text-slate-500">No notifications.</li>
      </ul>
    </div>
  </AdminLayout>
</template>
