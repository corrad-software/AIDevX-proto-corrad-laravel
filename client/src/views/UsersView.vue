<script setup lang="ts">
import { onMounted, ref } from "vue";
import {
  Users,
  Plus,
  Pencil,
  Trash2,
  CheckCircle2,
  XCircle,
} from "lucide-vue-next";

import AdminLayout from "@/layouts/AdminLayout.vue";
import { listUsers, deleteUser, getLookupsWithFallback } from "@/api/cms";
import { useConfirmDialog } from "@/composables/useConfirmDialog";
import { useToast } from "@/composables/useToast";
import type { UserDetail, UserLevelLookupRow } from "@/types";
import { coerceUserLevel, displayLabelForUserLevel } from "@/types";
import { ensureCsrfCookie } from "@/api/client";

const users = ref<UserDetail[]>([]);
const userLevelLookupRows = ref<UserLevelLookupRow[]>([]);
const confirmDialog = useConfirmDialog();
const toast = useToast();

async function load() {
  try {
    await ensureCsrfCookie();
    const usersRes = await listUsers();
    users.value = Array.isArray(usersRes.data) ? usersRes.data : [];
  } catch (e: unknown) {
    users.value = [];
    toast.error("Failed to load users", e instanceof Error ? e.message : "Could not fetch user list.");
  }
  try {
    const lookupsMerged = await getLookupsWithFallback();
    userLevelLookupRows.value = lookupsMerged.userLevel;
  } catch {
    userLevelLookupRows.value = [];
  }
}

function userLevelCellLabel(userLevel: string | undefined): string {
  return displayLabelForUserLevel(coerceUserLevel(userLevel), userLevelLookupRows.value);
}

async function remove(id: number) {
  const allowed = await confirmDialog.confirm({
    title: "Delete user?",
    message: "This action cannot be undone.",
    confirmText: "Delete",
    destructive: true,
  });
  if (!allowed) return;
  try {
    await deleteUser(id);
    await load();
    toast.success("User deleted");
  } catch (e) {
    toast.error("Delete failed", e instanceof Error ? e.message : "Unable to delete user.");
  }
}

onMounted(load);
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-7xl space-y-4">
      <!-- ───── Hero Header ───── -->
      <div class="flex items-center justify-between">
        <h1 class="page-title">Users</h1>
        <router-link
          to="/admin/platform/identity/users/new"
          class="flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-1.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-slate-800"
        >
          <Plus class="h-4 w-4" />
          Add User
        </router-link>
      </div>

      <!-- ───── Users Table ───── -->
      <article class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
          <Users class="h-4 w-4 text-blue-600" />
          <h2 class="text-sm font-semibold text-slate-900">All Users</h2>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-100 text-left">
                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Name</th>
                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Email</th>
                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Level</th>
                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Role</th>
                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Customer</th>
                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="user in users" :key="user.id" class="transition-colors hover:bg-slate-50">
                <td class="px-4 py-2 font-medium text-slate-900">
                  <router-link :to="'/admin/platform/identity/users/' + user.id" class="hover:text-violet-600">{{ user.name }}</router-link>
                </td>
                <td class="px-4 py-2 text-slate-600">{{ user.email }}</td>
                <td class="px-4 py-2">
                  <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-600">
                    {{ userLevelCellLabel(user.userLevel) }}
                  </span>
                </td>
                <td class="px-4 py-2 text-slate-600">{{ user.roles?.length ? user.roles.map(r => r.name).join(", ") : "—" }}</td>
                <td class="px-4 py-2 text-slate-600">
                  {{
                    user.customers?.length
                      ? user.customers.map((c) => (c.customerName?.trim() ? c.customerName : c.customerCode)).join(", ")
                      : "—"
                  }}
                </td>
                <td class="px-4 py-2">
                  <span v-if="user.isActive" class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                    <CheckCircle2 class="h-3 w-3" /> Active
                  </span>
                  <span v-else class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
                    <XCircle class="h-3 w-3" /> Inactive
                  </span>
                </td>
                <td class="px-4 py-2 text-right">
                  <div class="flex items-center justify-end gap-1.5">
                    <router-link :to="'/admin/platform/identity/users/' + user.id" class="group relative flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700">
                      <Pencil class="h-3.5 w-3.5" />
                      <span class="pointer-events-none absolute -top-8 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100">Edit</span>
                    </router-link>
                    <button class="group relative flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-rose-50 hover:text-rose-600" @click="remove(user.id)">
                      <Trash2 class="h-3.5 w-3.5" />
                      <span class="pointer-events-none absolute -top-8 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100">Delete</span>
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="users.length === 0">
                <td colspan="7" class="px-4 py-6 text-center text-sm text-slate-400">No users found.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>
    </div>
  </AdminLayout>
</template>
