<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { KeyRound } from "lucide-vue-next";
import { resetPassword } from "@/api/auth";
import { useToast } from "@/composables/useToast";

const route = useRoute();
const router = useRouter();
const toast = useToast();

const email = ref("");
const token = ref("");
const password = ref("");
const passwordConfirmation = ref("");
const loading = ref(false);

const canSubmit = computed(() => email.value && token.value && password.value.length >= 6 && password.value === passwordConfirmation.value);

onMounted(() => {
  email.value = (route.query.email as string) || "";
  token.value = (route.query.token as string) || "";
});

async function submit() {
  if (!canSubmit.value) return;
  loading.value = true;
  try {
    const res = await resetPassword({
      email: email.value,
      token: token.value,
      password: password.value,
      passwordConfirmation: passwordConfirmation.value,
    });
    toast.success(res.data.message);
    router.push("/admin/login");
  } catch (e) {
    toast.error(e instanceof Error ? e.message : "Reset failed");
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center bg-slate-100 px-4 dark:bg-slate-950">
    <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 shadow-lg dark:border-slate-700 dark:bg-slate-900">
      <div class="mb-6 flex items-center gap-2">
        <KeyRound class="h-8 w-8 text-slate-700 dark:text-slate-200" />
        <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">Set new password</h1>
      </div>
      <form class="space-y-4" @submit.prevent="submit">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Email</label>
          <input v-model="email" type="email" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">New password</label>
          <input v-model="password" type="password" required minlength="6" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Confirm password</label>
          <input v-model="passwordConfirmation" type="password" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" />
        </div>
        <button
          type="submit"
          :disabled="loading || !canSubmit"
          class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white disabled:opacity-50"
        >
          {{ loading ? "Saving…" : "Update password" }}
        </button>
      </form>
      <router-link to="/admin/login" class="mt-4 block text-center text-sm text-slate-600 hover:underline dark:text-slate-400">Sign in</router-link>
    </div>
  </div>
</template>
