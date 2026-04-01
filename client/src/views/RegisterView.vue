<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import { UserPlus } from "lucide-vue-next";

import { register } from "@/api/auth";
import { listActiveCustomers } from "@/api/cms";
import { useToast } from "@/composables/useToast";
import type { Customer } from "@/types";

const router = useRouter();
const toast = useToast();

const form = ref({
  name: "",
  email: "",
  password: "",
  password_confirmation: "",
  customerCode: "",
});

const customers = ref<Customer[]>([]);
const loading = ref(false);
const submitting = ref(false);

async function loadCustomers() {
  try {
    const res = await listActiveCustomers();
    customers.value = res.data;
  } catch {
    customers.value = [];
  }
}

async function submit() {
  if (!form.value.name || !form.value.email || !form.value.password || !form.value.customerCode) {
    toast.error("Please fill in all required fields.");
    return;
  }
  if (form.value.password.length < 6) {
    toast.error("Password must be at least 6 characters.");
    return;
  }
  if (form.value.password !== form.value.password_confirmation) {
    toast.error("Passwords do not match.");
    return;
  }

  submitting.value = true;
  try {
    await register(form.value);
    toast.success("Pendaftaran berjaya! Sila semak e-mel anda untuk mengesahkan akaun.");
    router.push("/admin/login");
  } catch (e: unknown) {
    const msg = e instanceof Error ? e.message : "Registration failed.";
    toast.error(msg);
  } finally {
    submitting.value = false;
  }
}

onMounted(loadCustomers);
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-slate-100 px-4">
    <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 shadow-lg">
      <div class="mb-6 flex items-center gap-2">
        <UserPlus class="h-8 w-8 text-slate-700" />
        <h1 class="text-xl font-bold text-slate-900">Create account</h1>
      </div>

      <form class="space-y-4" @submit.prevent="submit">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Name</label>
          <input
            v-model="form.name"
            type="text"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
            placeholder="Full name"
            required
          />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Email (username)</label>
          <input
            v-model="form.email"
            type="email"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
            placeholder="email@example.com"
            required
          />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Customer code</label>
          <select
            v-model="form.customerCode"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
            required
          >
            <option value="">-- Select customer code --</option>
            <option v-for="c in customers" :key="c.id" :value="c.customerCode">
              {{ c.customerCode }} - {{ c.customerName }}
            </option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Password</label>
          <input
            v-model="form.password"
            type="password"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
            placeholder="Min. 6 characters"
            required
          />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Confirm password</label>
          <input
            v-model="form.password_confirmation"
            type="password"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
            placeholder="Re-enter password"
            required
          />
        </div>
        <button
          type="submit"
          :disabled="submitting"
          class="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-slate-800 disabled:opacity-50"
        >
          {{ submitting ? "Processing..." : "Register" }}
        </button>
      </form>

      <p class="mt-4 text-center text-sm text-slate-500">
        Already have an account?
        <router-link to="/admin/login" class="font-medium text-slate-700 underline hover:text-slate-900">Sign in</router-link>
      </p>
    </div>
  </div>
</template>
