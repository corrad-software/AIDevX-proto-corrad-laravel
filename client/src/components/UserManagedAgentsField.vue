<script setup lang="ts">
/**
 * Dedicated UI for assigning agents under a manager (Level 0–3) on create/update user.
 * Loads options from GET /api/users/agent-picklist (hierarchy-scoped).
 */
import { computed, onMounted, ref, watch } from "vue";
import { RefreshCw } from "lucide-vue-next";

import { listAgentPicklist } from "@/api/cms";
import type { AgentPicklistItem, UserLevel } from "@/types";
import { coerceUserLevel, userLevelCanHaveManagedAgents } from "@/types";

const props = withDefaults(
  defineProps<{
    /** v-model: selected agent user IDs */
    modelValue: number[];
    /** Current target user level (manager being edited) */
    targetUserLevel: UserLevel | string | undefined;
    /** Exclude from picklist (e.g. user being edited cannot assign self) */
    excludeUserId?: number | null;
    disabled?: boolean;
  }>(),
  { disabled: false, excludeUserId: null },
);

const emit = defineEmits<{
  "update:modelValue": [value: number[]];
}>();

const picklist = ref<AgentPicklistItem[]>([]);
const error = ref("");
const loading = ref(false);

/** Pentadbir yang sedang disunting mestilah L0–L3; ibu bapa juga boleh semak. */
const applies = computed(() => userLevelCanHaveManagedAgents(coerceUserLevel(String(props.targetUserLevel ?? ""))));

const selected = computed({
  get: () => props.modelValue,
  set: (v: number[]) => emit("update:modelValue", v),
});

async function load() {
  if (!applies.value) {
    picklist.value = [];
    return;
  }
  loading.value = true;
  error.value = "";
  try {
    const res = await listAgentPicklist(props.excludeUserId ?? undefined);
    picklist.value = res.data ?? [];
  } catch (e: unknown) {
    picklist.value = [];
    error.value = e instanceof Error ? e.message : "Gagal memuat senarai ejen";
  } finally {
    loading.value = false;
  }
}

watch(
  () => [props.targetUserLevel, props.excludeUserId] as const,
  () => {
    void load();
  },
  { flush: "post" },
);

onMounted(() => {
  void load();
});
</script>

<template>
  <div class="col-span-full w-full min-w-0 space-y-1.5">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <label class="text-sm font-medium text-slate-700">Ejen (dilantik)</label>
      <button
        type="button"
        class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2 py-1 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-50"
        :disabled="disabled || loading"
        @click="load"
      >
        <RefreshCw class="h-3 w-3" :class="{ 'animate-spin': loading }" />
        Muat semula
      </button>
    </div>
    <p class="text-xs text-slate-500">
      Satu pengguna (Level 0–3) boleh mempunyai <strong>beberapa ejen</strong> yang dilapor kepadanya — sama seperti beberapa pelanggan.
      Tandakan ejen di bawah (Level 3). Untuk ejen mengikut pelanggan tertentu, gunakan pilihan di bawah setiap pelanggan.
    </p>
    <p v-if="!applies" class="rounded-md border border-slate-200 bg-slate-50 px-2 py-2 text-xs text-slate-600">
      Tahap pengguna Level 4 tidak menyokong senarai ejen dilantik.
    </p>
    <template v-else>
      <p v-if="error" class="rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5 text-xs text-amber-800">
        {{ error }}
      </p>
      <div class="max-h-48 overflow-y-auto rounded-lg border border-slate-300 bg-white p-3">
        <p v-if="loading && picklist.length === 0" class="py-2 text-xs text-slate-500">Memuat senarai ejen…</p>
        <template v-else>
          <label
            v-for="a in picklist"
            :key="a.id"
            class="flex cursor-pointer items-center gap-2 py-1.5 text-sm"
          >
            <input
              v-model="selected"
              type="checkbox"
              :value="a.id"
              :disabled="disabled"
              class="rounded border-slate-300 text-violet-600"
            />
            {{ a.name }} — {{ a.email }}
          </label>
          <p v-if="!loading && picklist.length === 0" class="py-2 text-xs text-slate-400">
            Tiada ejen dalam skop anda. Pastikan ada pengguna Level 3 (ejen) dalam hierarki atau tambah ejen dahulu.
          </p>
        </template>
      </div>
    </template>
  </div>
</template>
