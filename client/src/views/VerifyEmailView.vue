<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import { CheckCircle2, XCircle } from "lucide-vue-next";

import { verifyEmail } from "@/api/auth";
import { useToast } from "@/composables/useToast";

const route = useRoute();
const router = useRouter();
const toast = useToast();

const status = ref<"loading" | "success" | "error">("loading");
const message = ref("");

onMounted(async () => {
  const token = route.query.token as string;
  if (!token) {
    status.value = "error";
    message.value = "Verification token not found.";
    return;
  }

  try {
    const res = await verifyEmail(token);
    status.value = "success";
    message.value = res.data.message;
    toast.success(res.data.message);
    setTimeout(() => router.push("/admin/login"), 2000);
  } catch (e: unknown) {
    status.value = "error";
    message.value = e instanceof Error ? e.message : "Invalid or expired verification token.";
    toast.error(message.value);
  }
});
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-slate-100 px-4">
    <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 shadow-lg text-center">
      <div v-if="status === 'loading'" class="py-8">
        <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-slate-200 border-t-slate-600"></div>
        <p class="mt-4 text-sm text-slate-500">Mengesahkan e-mel...</p>
      </div>
      <div v-else-if="status === 'success'" class="py-8">
        <CheckCircle2 class="mx-auto h-16 w-16 text-emerald-500" />
        <h2 class="mt-4 text-lg font-semibold text-slate-900">Email verified</h2>
        <p class="mt-2 text-sm text-slate-600">{{ message }}</p>
        <p class="mt-4 text-xs text-slate-400">Redirecting to sign-in...</p>
      </div>
      <div v-else class="py-8">
        <XCircle class="mx-auto h-16 w-16 text-rose-500" />
        <h2 class="mt-4 text-lg font-semibold text-slate-900">Verification failed</h2>
        <p class="mt-2 text-sm text-slate-600">{{ message }}</p>
        <router-link
          to="/admin/login"
          class="mt-6 inline-block rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
        >
          Back to sign in
        </router-link>
      </div>
    </div>
  </div>
</template>
