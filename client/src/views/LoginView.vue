<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { ArrowRight, AlertCircle, Eye, EyeOff } from "lucide-vue-next";
import { ensureCsrfCookie } from "@/api/client";
import { useAuthStore } from "@/stores/auth";
import * as BRANDING from "@/config/branding";

const router = useRouter();
const auth = useAuthStore();

const email = ref("admin@example.com");
const password = ref("admin12345");
const error = ref("");
const showPassword = ref(false);

onMounted(() => {
  void ensureCsrfCookie(true);
});

async function submit() {
  error.value = "";
  try {
    await auth.signIn(email.value, password.value);
    router.push("/admin");
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Sign-in failed. Check your email and password.";
  }
}
</script>

<template>
  <div
    class="flex min-h-screen flex-col items-center justify-center bg-[#f6f9fc] px-4 dark:bg-slate-950"
  >
    <div class="w-full max-w-[400px]">

      <!-- Logo (unchanged asset) -->
      <div class="mb-7 flex flex-col items-center gap-2">
        <img src="/kerisi-logo.png" :alt="BRANDING.PLATFORM_HEADER" class="h-14 w-auto object-contain" />
        <span class="text-center text-xs font-semibold uppercase tracking-widest text-[#697386] dark:text-slate-400">
          {{ BRANDING.PLATFORM_HEADER }}
        </span>
        <span class="text-center text-[11px] font-medium text-[#8792a2] dark:text-slate-500">
          {{ BRANDING.PLATFORM_SUBTITLE }}
        </span>
      </div>

      <!-- Card -->
      <div
        class="rounded-xl border border-[#e3e8ee] bg-white px-10 pb-10 pt-8 shadow-[0_2px_4px_rgba(0,0,0,0.05),0_1px_2px_rgba(0,0,0,0.06)] dark:border-slate-700 dark:bg-slate-900 dark:shadow-none"
      >
        <h1 class="mb-1 text-center text-xl font-semibold tracking-tight text-[#1a1f36] dark:text-slate-100">Sign in</h1>
        <div class="mb-8" />

        <!-- Form -->
        <form class="space-y-5" @submit.prevent="submit">
          <div class="space-y-1.5">
            <label class="text-[13px] font-medium text-[#1a1f36] dark:text-slate-200">Email</label>
            <input
              v-model="email"
              type="email"
              autocomplete="email"
              class="w-full rounded-md border border-[#d8dee4] bg-white px-3 py-[9px] text-sm text-[#1a1f36] shadow-[0_1px_2px_rgba(0,0,0,0.04)] transition-shadow placeholder:text-[#a3acb9] focus:border-[#5469d4] focus:outline-none focus:ring-2 focus:ring-[#5469d4]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500"
              placeholder="you@example.com"
            />
          </div>

          <div class="space-y-1.5">
            <label class="text-[13px] font-medium text-[#1a1f36] dark:text-slate-200">Password</label>
            <div class="relative">
              <input
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                autocomplete="current-password"
                class="w-full rounded-md border border-[#d8dee4] bg-white px-3 py-[9px] pr-10 text-sm text-[#1a1f36] shadow-[0_1px_2px_rgba(0,0,0,0.04)] transition-shadow placeholder:text-[#a3acb9] focus:border-[#5469d4] focus:outline-none focus:ring-2 focus:ring-[#5469d4]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500"
                placeholder="Masukkan password"
              />
              <button
                type="button"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-[#a3acb9] transition-colors hover:text-[#697386]"
                @click="showPassword = !showPassword"
              >
                <EyeOff v-if="showPassword" class="h-4 w-4" />
                <Eye v-else class="h-4 w-4" />
              </button>
            </div>
          </div>

          <!-- Error -->
          <div v-if="error" class="flex items-center gap-2 rounded-md border border-[#f8d7da] bg-[#fdf2f2] px-3.5 py-2.5 text-[13px] text-[#cd3d64]">
            <AlertCircle class="h-4 w-4 shrink-0" />
            {{ error }}
          </div>

          <button
            type="submit"
            class="flex w-full items-center justify-center gap-2 rounded-md bg-[#5469d4] px-4 py-[9px] text-sm font-medium text-white shadow-[0_1px_2px_rgba(0,0,0,0.08),0_2px_4px_rgba(0,0,0,0.04)] transition-all hover:bg-[#4558b8] disabled:opacity-60"
            :disabled="auth.loading"
          >
            {{ auth.loading ? "Signing in..." : "Sign in" }}
            <ArrowRight v-if="!auth.loading" class="h-4 w-4" />
          </button>
        </form>
      </div>

      <!-- Footer -->
      <p class="mt-4 text-center text-[13px] text-[#697386] dark:text-slate-400">
        <router-link to="/admin/forgot-password" class="font-medium text-[#5469d4] hover:underline">Forgot password?</router-link>
      </p>
      <p class="mt-2 text-center text-[12px] text-[#8792a2] dark:text-slate-500">
        Didn&apos;t get the verification email?
        <router-link to="/admin/resend-verification" class="font-medium text-[#5469d4] hover:underline">Resend link</router-link>
      </p>
      <p class="mt-4 text-center text-[13px] text-[#697386]">
        Don&apos;t have an account?
        <router-link to="/admin/register" class="font-medium text-[#5469d4] hover:underline">Register</router-link>
      </p>
      <p class="mt-4 text-center text-[12px] text-[#8792a2] dark:text-slate-500">
        &copy; {{ new Date().getFullYear() }} Datascience Sdn Bhd &mdash; {{ BRANDING.PLATFORM_HEADER }}
      </p>
    </div>
  </div>
</template>
