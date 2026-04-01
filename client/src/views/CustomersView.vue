<script setup lang="ts">
import { onMounted, ref } from "vue";
import { Building2, Plus, Pencil, Trash2, CheckCircle2, XCircle } from "lucide-vue-next";

import AdminLayout from "@/layouts/AdminLayout.vue";
import { listCustomers, deleteCustomer } from "@/api/cms";
import { useConfirmDialog } from "@/composables/useConfirmDialog";
import { useToast } from "@/composables/useToast";
import type { Customer } from "@/types";

const customers = ref<Customer[]>([]);
const loading = ref(false);
const confirmDialog = useConfirmDialog();
const toast = useToast();

async function load() {
  loading.value = true;
  try {
    const res = await listCustomers();
    customers.value = res.data;
  } catch (e) {
    customers.value = [];
    toast.error("Failed to load customers", e instanceof Error ? e.message : undefined);
  } finally {
    loading.value = false;
  }
}

async function remove(id: number) {
  const allowed = await confirmDialog.confirm({
    title: "Delete customer?",
    message: "This action cannot be undone.",
    confirmText: "Delete",
    destructive: true,
  });
  if (!allowed) return;
  try {
    await deleteCustomer(id);
    await load();
    toast.success("Customer deleted");
  } catch (e) {
    toast.error("Delete failed", e instanceof Error ? e.message : "Unable to delete customer.");
  }
}

onMounted(load);
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-7xl space-y-4">
      <div class="flex items-center justify-between">
        <h1 class="page-title">Customer Setup</h1>
        <router-link
          to="/admin/platform/customers/new"
          class="flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-1.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-slate-800"
        >
          <Plus class="h-4 w-4" />
          New Customer
        </router-link>
      </div>

      <article class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
          <Building2 class="h-4 w-4 text-blue-600" />
          <h2 class="text-sm font-semibold text-slate-900">All Customers</h2>
        </div>
        <div v-if="loading" class="px-4 py-8 text-center text-sm text-slate-400">Loading...</div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-100 text-left">
                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Code</th>
                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Name</th>
                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Contact No</th>
                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Email</th>
                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500">System</th>
                <th class="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="c in customers" :key="c.id" class="transition-colors hover:bg-slate-50">
                <td class="px-4 py-2 font-mono font-medium text-slate-900">{{ c.customerCode }}</td>
                <td class="px-4 py-2 text-slate-600">{{ c.customerName }}</td>
                <td class="px-4 py-2 text-slate-600">{{ c.contactNo ?? "—" }}</td>
                <td class="px-4 py-2 text-slate-600">{{ c.email ?? "—" }}</td>
                <td class="px-4 py-2 text-slate-600">{{ c.systemName ?? "—" }}</td>
                <td class="px-4 py-2">
                  <span v-if="c.isActive" class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                    <CheckCircle2 class="h-3 w-3" /> Active
                  </span>
                  <span v-else class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">
                    <XCircle class="h-3 w-3" /> Inactive
                  </span>
                </td>
                <td class="px-4 py-2 text-right">
                  <router-link
                    :to="`/admin/platform/customers/${c.id}`"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700"
                  >
                    <Pencil class="h-3.5 w-3.5" />
                  </router-link>
                  <button
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-rose-50 hover:text-rose-600"
                    @click="remove(c.id)"
                  >
                    <Trash2 class="h-3.5 w-3.5" />
                  </button>
                </td>
              </tr>
              <tr v-if="customers.length === 0">
                <td colspan="7" class="px-4 py-6 text-center text-sm text-slate-400">No customers.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>
    </div>
  </AdminLayout>
</template>
