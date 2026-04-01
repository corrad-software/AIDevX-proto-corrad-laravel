<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { Building2, ArrowLeft, Save } from "lucide-vue-next";

import AdminLayout from "@/layouts/AdminLayout.vue";
import { getCustomer, createCustomer, updateCustomer, getLookupsWithFallback } from "@/api/cms";
import { useToast } from "@/composables/useToast";
import type { CustomerInput } from "@/types";

const route = useRoute();
const router = useRouter();
const toast = useToast();

const isNew = computed(() => route.name === "platform-customer-create" || route.params.id === "new");
const customerId = computed(() => {
  if (isNew.value) return null;
  const id = route.params.id;
  const n = Number(id);
  return Number.isNaN(n) ? null : n;
});

const form = ref<CustomerInput>({
  customerCode: "",
  customerName: "",
  contactNo: "",
  email: "",
  systemName: "",
  version: "",
  description: "",
  isActive: true,
});

const systemOptions = ref<string[]>(["KERISI", "iAGC", "eGPA"]);
const loading = ref(true);
const saving = ref(false);

async function loadLookups() {
  try {
    const merged = await getLookupsWithFallback();
    if (merged.system.length) systemOptions.value = merged.system;
  } catch {
    // use defaults
  }
}

async function load() {
  if (isNew.value || customerId.value == null) {
    await loadLookups();
    form.value = {
      customerCode: "",
      customerName: "",
      contactNo: "",
      email: "",
      systemName: "",
      version: "",
      description: "",
      isActive: true,
    };
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    await loadLookups();
    const res = await getCustomer(customerId.value);
    const c = res.data;
    form.value = {
      customerCode: c.customerCode,
      customerName: c.customerName,
      contactNo: c.contactNo ?? "",
      email: c.email ?? "",
      systemName: c.systemName ?? "",
      version: c.version ?? "",
      description: c.description ?? "",
      isActive: c.isActive,
    };
  } catch {
    toast.error("Failed to load customer");
    router.push("/admin/platform/customers");
  } finally {
    loading.value = false;
  }
}

async function save() {
  if (!form.value.customerCode || !form.value.customerName) {
    toast.error("Code and Name are required.");
    return;
  }
  saving.value = true;
  try {
    if (isNew.value) {
      await createCustomer(form.value);
      toast.success("Customer created");
    } else {
      await updateCustomer(customerId.value!, form.value);
      toast.success("Customer updated");
    }
    router.push("/admin/platform/customers");
  } catch (e: unknown) {
    toast.error("Save failed", e instanceof Error ? e.message : "Unable to save.");
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-2xl space-y-4">
      <div class="flex items-center gap-3">
        <router-link
          to="/admin/platform/customers"
          class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700"
        >
          <ArrowLeft class="h-4 w-4" />
        </router-link>
        <h1 class="page-title">{{ isNew ? "New Customer" : "Edit Customer" }}</h1>
      </div>

      <article v-if="!loading" class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
          <Building2 class="h-4 w-4 text-blue-600" />
          <h2 class="text-sm font-semibold text-slate-900">Customer Information</h2>
        </div>
        <div class="p-4 space-y-4">
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Code</label>
            <input
              v-model="form.customerCode"
              type="text"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="e.g. 001"
              :readonly="!isNew"
            />
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Name</label>
            <input
              v-model="form.customerName"
              type="text"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="Company name"
            />
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Contact No</label>
            <input
              v-model="form.contactNo"
              type="text"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="Phone number"
            />
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Email</label>
            <input
              v-model="form.email"
              type="email"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="email@example.com"
            />
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">System Name</label>
            <select
              v-model="form.systemName"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            >
              <option value="">— Select —</option>
              <option v-for="opt in systemOptions" :key="opt" :value="opt">{{ opt }}</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Version</label>
            <input
              v-model="form.version"
              type="text"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="e.g. 1.0"
            />
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Remarks</label>
            <textarea
              v-model="form.description"
              rows="2"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="Optional notes"
            />
          </div>
          <div class="flex items-center gap-3">
            <label class="inline-flex cursor-pointer items-center gap-2">
              <input v-model="form.isActive" type="checkbox" class="rounded border-slate-300" />
              <span class="text-sm text-slate-700">Active</span>
            </label>
          </div>
          <div class="flex justify-end">
            <button
              class="flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
              :disabled="saving || !form.customerCode || !form.customerName"
              @click="save"
            >
              <Save class="h-4 w-4" />
              Save
            </button>
          </div>
        </div>
      </article>
    </div>
  </AdminLayout>
</template>
