<script setup lang="ts">
import { computed } from "vue";
import { CheckCircle2, Info, XCircle } from "lucide-vue-next";
import { useToast } from "@/composables/useToast";

const { toasts } = useToast();
const latestToast = computed(() => toasts.value.at(-1) ?? null);

function variantClasses(variant: "success" | "error" | "info") {
  if (variant === "success") return "bg-gradient-to-br from-emerald-200 to-emerald-100 text-emerald-950";
  if (variant === "error") return "bg-gradient-to-br from-rose-200 to-rose-100 text-rose-950";
  return "bg-gradient-to-br from-blue-200 to-blue-100 text-blue-950";
}

function variantLabel(variant: "success" | "error" | "info") {
  if (variant === "success") return "Success";
  if (variant === "error") return "Error";
  return "Info";
}

function iconTone(variant: "success" | "error" | "info") {
  if (variant === "success") return "bg-emerald-200 text-emerald-800 ring-1 ring-emerald-400/70";
  if (variant === "error") return "bg-rose-200 text-rose-800 ring-1 ring-rose-400/70";
  return "bg-blue-200 text-blue-800 ring-1 ring-blue-400/70";
}
</script>

<template>
  <div
    class="flex h-full items-stretch gap-2 overflow-hidden py-0"
    :class="latestToast?.variant === 'error' ? 'max-w-[28rem]' : 'max-w-[22rem]'"
  >
    <Transition
      enter-active-class="transition-transform duration-[600ms] ease-[cubic-bezier(0.22,1,0.36,1)]"
      enter-from-class="translate-x-[110%]"
      enter-to-class="translate-x-0"
      leave-active-class="transition-transform duration-[1500ms] ease-[cubic-bezier(0.4,0,1,1)]"
      leave-from-class="translate-x-0"
      leave-to-class="translate-x-[110%]"
    >
      <div
        v-if="latestToast"
        :key="latestToast.id"
        class="relative flex h-full min-w-[14rem] items-center overflow-hidden px-2 py-1 shadow-sm will-change-transform"
        :class="variantClasses(latestToast.variant)"
      >
        <div class="flex items-center gap-2">
          <div class="rounded-full p-0.5" :class="iconTone(latestToast.variant)">
            <CheckCircle2 v-if="latestToast.variant === 'success'" class="h-3.5 w-3.5 shrink-0" />
            <XCircle v-else-if="latestToast.variant === 'error'" class="h-3.5 w-3.5 shrink-0" />
            <Info v-else class="h-3.5 w-3.5 shrink-0" />
          </div>

          <div class="min-w-0 flex-1">
            <p class="truncate text-[10px] font-semibold uppercase leading-none tracking-[0.11em] opacity-70">{{ variantLabel(latestToast.variant) }}</p>
            <p
              class="mt-[2px] text-xs font-semibold leading-tight"
              :class="latestToast.variant === 'error' ? 'break-words whitespace-normal' : 'truncate'"
            >
              {{ latestToast.title }}<span v-if="latestToast.message" class="font-normal opacity-90"> - {{ latestToast.message }}</span>
            </p>
          </div>

        </div>
      </div>
    </Transition>
  </div>
</template>
