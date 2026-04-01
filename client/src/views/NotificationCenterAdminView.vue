<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import { Bell, RefreshCw, Send, Trash2, UserRoundSearch, X } from "lucide-vue-next";
import AdminLayout from "@/layouts/AdminLayout.vue";
import { useAuthStore } from "@/stores/auth";
import {
  adminDeleteNotification,
  adminResendNotificationEmail,
  adminSendNotification,
  listAdminNotifications,
  searchUsersForPicker,
} from "@/api/cms";
import type { InAppNotification, UserDetail } from "@/types";
import { useToast } from "@/composables/useToast";
import { useConfirmDialog } from "@/composables/useConfirmDialog";

const router = useRouter();
const auth = useAuthStore();
const toast = useToast();
const { confirm } = useConfirmDialog();

const rows = ref<InAppNotification[]>([]);
const loading = ref(true);
const page = ref(1);
const totalPages = ref(1);

/** Search box (type from 1 character). */
const recipientQuery = ref("");
const recipientSuggestions = ref<UserDetail[]>([]);
const recipientSearchOpen = ref(false);
const recipientSearchLoading = ref(false);
/** Shown when API returned no rows, or every match is already selected. */
const recipientSearchHint = ref<"" | "no_match" | "all_selected">("");
const selectedRecipients = ref<UserDetail[]>([]);

let searchDebounce: ReturnType<typeof setTimeout> | null = null;
let recipientBlurTimer: ReturnType<typeof setTimeout> | null = null;

const sendTitle = ref("");
const sendBody = ref("");
const sendEmail = ref(true);
const sending = ref(false);

function hasAdmin() {
  const p = auth.user?.permissions;
  return p?.includes("notifications.admin");
}

function selectedIds(): Set<number> {
  return new Set(selectedRecipients.value.map((u) => u.id));
}

async function runRecipientSearch() {
  const q = recipientQuery.value.trim();
  if (q.length < 1) {
    recipientSuggestions.value = [];
    recipientSearchLoading.value = false;
    recipientSearchHint.value = "";
    return;
  }
  recipientSearchLoading.value = true;
  recipientSearchHint.value = "";
  try {
    const res = await searchUsersForPicker(q, 25);
    const taken = selectedIds();
    const all = res.data;
    recipientSuggestions.value = all.filter((u) => !taken.has(u.id));
    if (all.length === 0) {
      recipientSearchHint.value = "no_match";
    } else if (recipientSuggestions.value.length === 0) {
      recipientSearchHint.value = "all_selected";
    }
  } catch {
    recipientSuggestions.value = [];
    recipientSearchHint.value = "";
  } finally {
    recipientSearchLoading.value = false;
  }
}

watch(recipientQuery, () => {
  if (searchDebounce) clearTimeout(searchDebounce);
  const q = recipientQuery.value.trim();
  if (q.length < 1) {
    recipientSuggestions.value = [];
    recipientSearchLoading.value = false;
    recipientSearchHint.value = "";
    return;
  }
  // Keep panel open while typing (avoids stale blur timer closing it after refocus)
  recipientSearchOpen.value = true;
  searchDebounce = setTimeout(() => {
    searchDebounce = null;
    void runRecipientSearch();
  }, 200);
});

function addRecipient(u: UserDetail) {
  if (selectedIds().has(u.id)) return;
  selectedRecipients.value = [...selectedRecipients.value, u];
  recipientQuery.value = "";
  recipientSuggestions.value = [];
  recipientSearchHint.value = "";
  recipientSearchOpen.value = false;
}

function removeRecipient(id: number) {
  selectedRecipients.value = selectedRecipients.value.filter((u) => u.id !== id);
  if (recipientQuery.value.trim().length >= 1) {
    void runRecipientSearch();
  }
}

async function load() {
  if (!hasAdmin()) {
    router.replace("/admin");
    return;
  }
  loading.value = true;
  try {
    const res = await listAdminNotifications(`?page=${page.value}&limit=30`);
    rows.value = res.data;
    const m = res.meta || {};
    totalPages.value = (m.totalPages as number) || (m.total_pages as number) || 1;
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed to load");
  } finally {
    loading.value = false;
  }
}

async function resend(n: InAppNotification) {
  try {
    await adminResendNotificationEmail(n.id);
    toast.success("Email resent (if mail is configured)");
    await load();
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed");
  }
}

async function remove(n: InAppNotification) {
  const ok = await confirm({
    title: "Delete notification?",
    message: "This removes the in-app row for that user.",
    destructive: true,
  });
  if (!ok) return;
  try {
    await adminDeleteNotification(n.id);
    toast.success("Deleted");
    await load();
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed");
  }
}

async function broadcast() {
  const ids = selectedRecipients.value.map((u) => u.id);
  if (!ids.length || !sendTitle.value.trim()) {
    toast.error("Pilih sekurang-kurangnya seorang penerima dan isi tajuk (title).");
    return;
  }
  sending.value = true;
  try {
    await adminSendNotification({
      userIds: ids,
      title: sendTitle.value.trim(),
      body: sendBody.value.trim() || undefined,
      notificationType: "system",
      module: "admin",
      sendEmail: sendEmail.value,
    });
    toast.success("Sent");
    sendTitle.value = "";
    sendBody.value = "";
    selectedRecipients.value = [];
    recipientQuery.value = "";
    await load();
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Failed");
  } finally {
    sending.value = false;
  }
}

function onRecipientFocus() {
  if (recipientBlurTimer) {
    clearTimeout(recipientBlurTimer);
    recipientBlurTimer = null;
  }
  recipientSearchOpen.value = true;
  if (recipientQuery.value.trim().length >= 1) void runRecipientSearch();
}

function onRecipientBlur() {
  // Delay so mousedown on suggestion runs first; cancel on refocus to avoid closing after second search
  if (recipientBlurTimer) clearTimeout(recipientBlurTimer);
  recipientBlurTimer = setTimeout(() => {
    recipientBlurTimer = null;
    recipientSearchOpen.value = false;
  }, 150);
}

onBeforeUnmount(() => {
  if (searchDebounce) clearTimeout(searchDebounce);
  if (recipientBlurTimer) clearTimeout(recipientBlurTimer);
});

onMounted(load);
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-6xl px-4 py-6">
      <h1 class="mb-6 flex items-center gap-2 text-xl font-semibold text-slate-900 dark:text-slate-100">
        <Bell class="h-6 w-6 text-[var(--accent-600)]" />
        Notification center (administration)
      </h1>

      <div class="mb-8 rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-900">
        <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
          <Send class="h-4 w-4" />
          Send notification
        </h2>
        <p class="mb-3 text-xs text-slate-500">
          Cari pengguna mengikut nama atau e-mel (taip 1 aksara ke atas). Pilih satu atau lebih penerima; baris dalam aplikasi dan e-mel pilihan.
        </p>

        <div class="grid gap-3 md:grid-cols-2">
          <div class="relative">
            <label class="mb-1 flex items-center gap-1 text-xs font-medium text-slate-600 dark:text-slate-400">
              <UserRoundSearch class="h-3.5 w-3.5" />
              Recipients
            </label>
            <input
              v-model="recipientQuery"
              type="text"
              autocomplete="off"
              placeholder="Taip nama atau e-mel…"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
              @focus="onRecipientFocus"
              @blur="onRecipientBlur"
            />
            <ul
              v-if="
                recipientSearchOpen &&
                recipientQuery.trim().length >= 1 &&
                (recipientSearchLoading || recipientSuggestions.length > 0 || recipientSearchHint)
              "
              class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg dark:border-slate-600 dark:bg-slate-900"
            >
              <li v-if="recipientSearchLoading" class="px-3 py-2 text-slate-500">Searching…</li>
              <li
                v-for="u in recipientSuggestions"
                :key="u.id"
                class="cursor-pointer px-3 py-2 hover:bg-slate-100 dark:hover:bg-slate-800"
                @mousedown.prevent="addRecipient(u)"
              >
                <span class="font-medium text-slate-800 dark:text-slate-100">{{ u.name }}</span>
                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ u.email }}</span>
              </li>
              <li
                v-if="!recipientSearchLoading && recipientSearchHint === 'no_match'"
                class="px-3 py-2 text-slate-500 dark:text-slate-400"
              >
                Tiada pengguna dijumpai.
              </li>
              <li
                v-if="!recipientSearchLoading && recipientSearchHint === 'all_selected'"
                class="px-3 py-2 text-slate-500 dark:text-slate-400"
              >
                Semua padanan sudah dipilih.
              </li>
            </ul>
            <div v-if="selectedRecipients.length" class="mt-2 flex flex-wrap gap-1.5">
              <span
                v-for="u in selectedRecipients"
                :key="u.id"
                class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-50 py-0.5 pl-2 pr-1 text-xs text-slate-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200"
              >
                <span class="max-w-[200px] truncate" :title="`${u.name} <${u.email}>`">{{ u.name }}</span>
                <span class="text-slate-400">·</span>
                <span class="max-w-[180px] truncate text-slate-500" :title="u.email">{{ u.email }}</span>
                <button
                  type="button"
                  class="rounded p-0.5 text-slate-500 hover:bg-slate-200 hover:text-slate-800 dark:hover:bg-slate-700"
                  title="Remove"
                  @click="removeRecipient(u.id)"
                >
                  <X class="h-3.5 w-3.5" />
                </button>
              </span>
            </div>
          </div>
          <div>
            <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Title</label>
            <input
              v-model="sendTitle"
              type="text"
              placeholder="Title"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
            />
          </div>
        </div>
        <textarea
          v-model="sendBody"
          rows="2"
          placeholder="Body (optional)"
          class="mt-3 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
        />
        <label class="mt-2 flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
          <input v-model="sendEmail" type="checkbox" class="rounded" />
          Send email (requires SMTP; skipped if mailer is log)
        </label>
        <button
          type="button"
          :disabled="sending"
          class="mt-3 rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
          @click="broadcast"
        >
          {{ sending ? "Sending…" : "Send" }}
        </button>
      </div>

      <div class="mb-3 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-300">All notifications (log)</h2>
        <button type="button" class="inline-flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900 dark:text-slate-400" @click="load">
          <RefreshCw class="h-4 w-4" />
          Refresh
        </button>
      </div>

      <div v-if="loading" class="py-12 text-center text-slate-500">Loading…</div>
      <div v-else class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 dark:bg-slate-800/80">
            <tr>
              <th class="px-3 py-2 font-medium">User</th>
              <th class="px-3 py-2 font-medium">Type</th>
              <th class="px-3 py-2 font-medium">Title</th>
              <th class="px-3 py-2 font-medium">Email</th>
              <th class="px-3 py-2 font-medium">When</th>
              <th class="px-3 py-2 font-medium" />
            </tr>
          </thead>
          <tbody>
            <tr v-for="n in rows" :key="n.id" class="border-t border-slate-100 dark:border-slate-800">
              <td class="px-3 py-2 text-slate-700 dark:text-slate-300">{{ n.user?.email ?? n.userId }}</td>
              <td class="px-3 py-2 text-slate-600 dark:text-slate-400">{{ n.notificationType }} / {{ n.module || "—" }}</td>
              <td class="max-w-xs truncate px-3 py-2 text-slate-800 dark:text-slate-200">{{ n.title }}</td>
              <td class="px-3 py-2 text-xs text-slate-500">{{ n.emailStatus }}</td>
              <td class="px-3 py-2 text-xs text-slate-500">{{ new Date(n.createdAt).toLocaleString() }}</td>
              <td class="px-3 py-2 text-right">
                <button type="button" class="mr-2 text-xs font-medium text-indigo-600 hover:underline" @click="resend(n)">Resend email</button>
                <button type="button" class="text-rose-600 hover:text-rose-700" title="Delete" @click="remove(n)">
                  <Trash2 class="h-4 w-4" />
                </button>
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="6" class="px-3 py-8 text-center text-slate-500">No rows.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="totalPages > 1" class="mt-4 flex justify-center gap-2">
        <button
          type="button"
          :disabled="page <= 1"
          class="rounded border px-3 py-1 text-sm disabled:opacity-40"
          @click="page--; load()"
        >
          Prev
        </button>
        <span class="py-1 text-sm text-slate-600">{{ page }} / {{ totalPages }}</span>
        <button
          type="button"
          :disabled="page >= totalPages"
          class="rounded border px-3 py-1 text-sm disabled:opacity-40"
          @click="page++; load()"
        >
          Next
        </button>
      </div>
    </div>
  </AdminLayout>
</template>
