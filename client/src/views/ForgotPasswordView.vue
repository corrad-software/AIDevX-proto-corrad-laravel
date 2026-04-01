<script setup lang="ts">
import { ref } from "vue";
import { useRouter } from "vue-router";
import { ArrowLeft, Mail } from "lucide-vue-next";
import { forgotPassword } from "@/api/auth";
import { useToast } from "@/composables/useToast";

const router = useRouter();
const toast = useToast();
const email = ref("");
const loading = ref(false);
const sent = ref(false);

async function submit() {
  loading.value = true;
  try {
    const res = await forgotPassword(email.value.trim());
    toast.success(res.data.message);
    sent.value = true;
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Request failed");
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center bg-slate-100 px-4 dark:bg-slate-950">
    <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 shadow-lg dark:border-slate-700 dark:bg-slate-900">
      <div class="mb-6 flex items-center gap-2">
        <Mail class="h-8 w-8 text-slate-700 dark:text-slate-200" />
        <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Forgot password</h1>
      </div>
      <p class="mb-4 text-sm text-slate-600 dark:text-slate-400">
        Enter your email. If an account exists, we will send a link to reset your password.
      </p>
      <form v-if="!sent" class="space-y-4" @submit.prevent="submit">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Email</label>
          <input
            v-model="email"
            type="email"
            required
            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
            placeholder="you@example.com"
          />
        </div>
        <button
          type="submit"
          :disabled="loading"
          class="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900"
        >
          {{ loading ? "Sending…" : "Send reset link" }}
        </button>
      </form>
      <router-link
        to="/admin/login"
        class="mt-6 inline-flex items-center gap-2 text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400"
      >
        <ArrowLeft class="h-4 w-4" />
        Back to sign in
      </router-link>
    </div>
  </div>
</template>
