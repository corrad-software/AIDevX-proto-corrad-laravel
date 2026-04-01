<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
  User,
  Lock,
  Camera,
  Save,
  CheckCircle2,
  Upload,
  Trash2,
  ArrowLeft,
  ChevronDown,
  Check,
} from "lucide-vue-next";

import AdminLayout from "@/layouts/AdminLayout.vue";
import UserManagedAgentsField from "@/components/UserManagedAgentsField.vue";
import { useAuthStore } from "@/stores/auth";
import { getUser, createUser, updateUser, listRoles, listCustomers, listAgentPicklist, getLookupsWithFallback } from "@/api/cms";
import { useConfirmDialog } from "@/composables/useConfirmDialog";
import { useToast } from "@/composables/useToast";
import { API_BASE_URL } from "@/env";
import { ensureCsrfCookie } from "@/api/client";
import type {
  Role,
  Customer,
  UserLevel,
  CodeDescLookupRow,
  CustomerAgentAssignmentRow,
  AgentPicklistItem,
  UserLevelLookupRow,
  UserLevelSelectOption,
} from "@/types";
import {
  USER_LEVELS,
  assignableUserLevelsForActor,
  coerceUserLevel,
  displayLabelForUserLevel,
  isEndUserLevel,
  userLevelCanHaveManagedAgents,
  userLevelOptionsForSelect,
} from "@/types";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const toast = useToast();
const confirmDialog = useConfirmDialog();

const isNew = computed(() => route.name === "platform-user-create" || route.params.id === "new");
const userId = computed(() => {
  if (isNew.value) return null;
  const id = route.params.id;
  const n = Number(id);
  return Number.isNaN(n) ? null : n;
});
const isSelf = computed(() => userId.value !== null && userId.value === auth.user?.id);

/** Agent/User viewing own profile: hide Roles, Customers read-only */
const isAgentOrUserSelf = computed(
  () => isSelf.value && ["agent", "user", "secondary_user"].includes(auth.user?.userLevel ?? ""),
);
const showRolesSection = computed(() => !isAgentOrUserSelf.value);
const canEditCustomers = computed(() => !isAgentOrUserSelf.value);

const profileForm = ref({
  name: "",
  email: "",
  roleIds: [] as number[],
  userLevel: "user" as UserLevel,
  userJenisPengguna: "" as string,
  customerIds: [] as number[],
  managedAgentIds: [] as number[],
  notes: "",
  isActive: true,
});

function defaultNewUserLevel(): UserLevel {
  const allowed = assignableUserLevelsForActor(auth.user?.userLevel);
  const firstStaff = allowed.find((l) => !isEndUserLevel(l));
  return firstStaff ?? allowed[0] ?? "user";
}

const userLevelLookupRows = ref<UserLevelLookupRow[]>([]);
const userJenisPenggunaOptions = ref<CodeDescLookupRow[]>([]);
const userJenisPenggunaSearch = ref("");
const userJenisPenggunaShowAll = ref(false);

const filteredUserJenisPenggunaOptions = computed(() => {
  const q = userJenisPenggunaSearch.value.trim().toLowerCase();
  if (!q) {
    return userJenisPenggunaOptions.value;
  }
  return userJenisPenggunaOptions.value.filter((opt) => {
    return opt.code.toLowerCase().includes(q) || opt.desc.toLowerCase().includes(q);
  });
});

const visibleUserJenisPenggunaOptions = computed(() => {
  if (userJenisPenggunaSearch.value.trim() !== "" || userJenisPenggunaShowAll.value) {
    return filteredUserJenisPenggunaOptions.value;
  }
  return filteredUserJenisPenggunaOptions.value.slice(0, 3);
});

const userJenisPenggunaHasMore = computed(() => filteredUserJenisPenggunaOptions.value.length > 3);

const userLevelSelectOptions = computed(() => {
  const allowed = new Set<UserLevel>(assignableUserLevelsForActor(auth.user?.userLevel));
  const cur = profileForm.value.userLevel;
  if (cur) {
    allowed.add(cur);
  }
  const levels = USER_LEVELS.filter((lvl) => allowed.has(lvl));
  return userLevelOptionsForSelect(levels, userLevelLookupRows.value);
});

const userLevelReadOnlyLabel = computed(() =>
  displayLabelForUserLevel(profileForm.value.userLevel, userLevelLookupRows.value),
);

const userLevelDropdownOpen = ref(false);
const userLevelDropdownRoot = ref<HTMLElement | null>(null);
/** Which lookup row is shown when several options share the same `value` (same DB tier). */
const userLevelSelectedOptionId = ref<string | null>(null);

const selectedUserLevelOption = computed(() => {
  const opts = userLevelSelectOptions.value;
  const id = userLevelSelectedOptionId.value;
  const lvl = profileForm.value.userLevel;
  if (id) {
    const byId = opts.find((o) => o.optionId === id);
    if (byId && byId.value === lvl) {
      return byId;
    }
  }
  return opts.find((o) => o.value === lvl) ?? null;
});

function pickUserLevel(opt: UserLevelSelectOption) {
  profileForm.value.userLevel = opt.value;
  userLevelSelectedOptionId.value = opt.optionId;
  userLevelDropdownOpen.value = false;
}

watch(userLevelSelectOptions, (opts) => {
  const id = userLevelSelectedOptionId.value;
  if (id && !opts.some((o) => o.optionId === id)) {
    userLevelSelectedOptionId.value = null;
  }
});

function onDocumentClickCloseUserLevel(e: MouseEvent) {
  const root = userLevelDropdownRoot.value;
  if (!root || !userLevelDropdownOpen.value) return;
  if (e.target instanceof Node && !root.contains(e.target)) {
    userLevelDropdownOpen.value = false;
  }
}

const passwordForm = ref({ currentPassword: "", newPassword: "", confirmPassword: "" });
const roles = ref<Role[]>([]);
const customers = ref<Customer[]>([]);

const loading = ref(true);
const savingProfile = ref(false);
const savingPassword = ref(false);
const uploadingAvatar = ref(false);
const profileSaved = ref(false);
const passwordChanged = ref(false);
const profileError = ref("");
const passwordError = ref("");
const avatarError = ref("");

/** customerId → agent user IDs (per-customer assignment for L0–3). */
const customerAgentMap = ref<Record<number, number[]>>({});
const agentPicklists = ref<Record<number, AgentPicklistItem[]>>({});
const picklistError = ref<Record<number, string>>({});
const picklistLoading = ref<Record<number, boolean>>({});
/** Elak watch customerIds bertindih semasa getUser() memuat map + picklist. */
const skipCustomerIdsWatch = ref(false);

function clearCustomerAgentState() {
  customerAgentMap.value = {};
  agentPicklists.value = {};
  picklistError.value = {};
  picklistLoading.value = {};
}

/** Pastikan v-model / API tidak campur string vs number — elak senarai ejen kosong. */
function normalizeCustomerIds(ids: readonly (number | string)[] | undefined): number[] {
  const out: number[] = [];
  const seen = new Set<number>();
  for (const raw of ids ?? []) {
    const n = Number(raw);
    if (!Number.isFinite(n) || n <= 0 || seen.has(n)) continue;
    seen.add(n);
    out.push(n);
  }
  return out;
}

function toggleCustomerAgent(customerId: number, agentId: number, checked: boolean) {
  const cur = new Set(customerAgentMap.value[customerId] ?? []);
  if (checked) cur.add(agentId);
  else cur.delete(agentId);
  customerAgentMap.value = { ...customerAgentMap.value, [customerId]: [...cur] };
}

function isCustomerAgentSelected(customerId: number, agentId: number): boolean {
  return (customerAgentMap.value[customerId] ?? []).includes(agentId);
}

async function loadPicklistForCustomer(customerId: number) {
  if (!userLevelCanHaveManagedAgents(profileForm.value.userLevel)) return;
  picklistLoading.value = { ...picklistLoading.value, [customerId]: true };
  picklistError.value = { ...picklistError.value, [customerId]: "" };
  try {
    const res = await listAgentPicklist(userId.value ?? undefined, { customerId });
    agentPicklists.value = { ...agentPicklists.value, [customerId]: res.data ?? [] };
  } catch (e: unknown) {
    agentPicklists.value = { ...agentPicklists.value, [customerId]: [] };
    picklistError.value = {
      ...picklistError.value,
      [customerId]: e instanceof Error ? e.message : "Gagal memuat ejen",
    };
  } finally {
    picklistLoading.value = { ...picklistLoading.value, [customerId]: false };
  }
}

function buildCustomerAgentAssignmentsPayload(): CustomerAgentAssignmentRow[] {
  if (!userLevelCanHaveManagedAgents(profileForm.value.userLevel)) return [];
  const rows: CustomerAgentAssignmentRow[] = [];
  for (const cid of normalizeCustomerIds(profileForm.value.customerIds)) {
    const ids = customerAgentMap.value[cid];
    if (ids?.length) rows.push({ customerId: cid, agentIds: [...ids] });
  }
  return rows;
}

const selectedCustomerIdSet = computed(
  () => new Set(normalizeCustomerIds(profileForm.value.customerIds)),
);

// For displaying avatar of the user being edited
const userPhotoUrl = ref<string | null>(null);

const userInitials = computed(() => {
  const name = profileForm.value.name;
  if (!name) return "U";
  return name
    .split(" ")
    .map((n) => n[0])
    .join("")
    .toUpperCase()
    .slice(0, 2);
});

const pageTitle = computed(() => {
  if (isNew.value) return "New User";
  if (isSelf.value) return "My Profile";
  return profileForm.value.name || "Edit User";
});

function resolveUrl(url: string) {
  if (!url) return "";
  if (url.startsWith("http")) return url;
  return `${API_BASE_URL}${url}`;
}

async function load() {
  loading.value = true;
  skipCustomerIdsWatch.value = true;
  try {
    await ensureCsrfCookie();
    const [rolesRes, customersRes, lookupsMerged] = await Promise.all([
      listRoles(),
      listCustomers(),
      getLookupsWithFallback(),
    ]);
    roles.value = rolesRes.data;
    customers.value = customersRes.data;
    userLevelLookupRows.value = lookupsMerged.userLevel;
    userJenisPenggunaOptions.value = lookupsMerged.userJenisPengguna ?? [];
    userJenisPenggunaSearch.value = "";
    userJenisPenggunaShowAll.value = false;

    if (isNew.value) {
      clearCustomerAgentState();
      profileForm.value = {
        name: "",
        email: "",
        roleIds: [],
        userLevel: defaultNewUserLevel(),
        userJenisPengguna: "",
        customerIds: [],
        managedAgentIds: [],
        notes: "",
        isActive: true,
      };
      userLevelSelectedOptionId.value = null;
      passwordForm.value = { currentPassword: "", newPassword: "", confirmPassword: "" };
      userPhotoUrl.value = null;
    } else if (userId.value) {
      const res = await getUser(userId.value);
      const u = res.data;
      profileForm.value = {
        name: u.name,
        email: u.email,
        roleIds: u.roleIds ?? [],
        userLevel: coerceUserLevel(u.userLevel),
        userJenisPengguna: u.userJenisPengguna ?? "",
        customerIds: normalizeCustomerIds(u.customerIds ?? []),
        managedAgentIds: u.managedAgentIds ?? [],
        notes: u.notes ?? "",
        isActive: u.isActive,
      };
      userLevelSelectedOptionId.value = null;

      clearCustomerAgentState();
      const nextMap: Record<number, number[]> = {};
      for (const row of u.customerAgentAssignments ?? []) {
        const rcid = Number(row.customerId);
        if (!Number.isFinite(rcid)) continue;
        nextMap[rcid] = [...row.agentIds.map((id) => Number(id))];
      }
      for (const cid of normalizeCustomerIds(profileForm.value.customerIds)) {
        if (nextMap[cid] === undefined) nextMap[cid] = [];
      }
      customerAgentMap.value = nextMap;

      // For self, use auth store's photoUrl (more up-to-date)
      if (isSelf.value) {
        userPhotoUrl.value = auth.user?.photoUrl || null;
      }

      if (userLevelCanHaveManagedAgents(profileForm.value.userLevel)) {
        await Promise.all(
          normalizeCustomerIds(profileForm.value.customerIds).map((cid) => loadPicklistForCustomer(cid)),
        );
      }
    }
  } catch (e: unknown) {
    profileError.value = e instanceof Error ? e.message : "Failed to load user";
  } finally {
    loading.value = false;
    skipCustomerIdsWatch.value = false;
  }
}

async function saveProfile() {
  savingProfile.value = true;
  profileError.value = "";
  try {
    if (isNew.value) {
      // Create new user
      if (!passwordForm.value.newPassword) {
        profileError.value = "Password is required for new users";
        savingProfile.value = false;
        return;
      }
      if (passwordForm.value.newPassword !== passwordForm.value.confirmPassword) {
        profileError.value = "Passwords do not match";
        savingProfile.value = false;
        return;
      }
      const createPayload: Parameters<typeof createUser>[0] = {
        name: profileForm.value.name,
        email: profileForm.value.email,
        password: passwordForm.value.newPassword,
        roleIds: profileForm.value.roleIds,
        userLevel: profileForm.value.userLevel,
        userJenisPengguna: profileForm.value.userJenisPengguna !== "" ? profileForm.value.userJenisPengguna : null,
        customerIds: profileForm.value.customerIds,
        isActive: profileForm.value.isActive,
        notes: profileForm.value.notes.trim() !== "" ? profileForm.value.notes : null,
      };
      if (userLevelCanHaveManagedAgents(profileForm.value.userLevel)) {
        createPayload.managedAgentIds = [...profileForm.value.managedAgentIds];
        createPayload.customerAgentAssignments = buildCustomerAgentAssignmentsPayload();
      }
      await createUser(createPayload);
      toast.success("User created");
      router.push("/admin/platform/identity/users");
      return;
    }

    const payload: Parameters<typeof updateUser>[1] = {
      name: profileForm.value.name,
      email: profileForm.value.email,
    };
    if (!isAgentOrUserSelf.value) {
      payload.roleIds = profileForm.value.roleIds;
      payload.userLevel = profileForm.value.userLevel;
      payload.userJenisPengguna = profileForm.value.userJenisPengguna !== "" ? profileForm.value.userJenisPengguna : null;
      payload.customerIds = profileForm.value.customerIds;
      payload.isActive = profileForm.value.isActive;
      payload.notes = profileForm.value.notes.trim() !== "" ? profileForm.value.notes : null;
      if (userLevelCanHaveManagedAgents(profileForm.value.userLevel)) {
        payload.managedAgentIds = [...profileForm.value.managedAgentIds];
        payload.customerAgentAssignments = buildCustomerAgentAssignmentsPayload();
      }
    }
    await updateUser(userId.value!, payload);
    if (isSelf.value) {
      await auth.refreshUser();
    }
    profileSaved.value = true;
    toast.success("Profile updated");
    setTimeout(() => { profileSaved.value = false; }, 2000);
  } catch (e: unknown) {
    profileError.value = e instanceof Error ? e.message : "Failed to save";
    toast.error("Save failed", profileError.value);
  } finally {
    savingProfile.value = false;
  }
}

async function savePassword() {
  passwordError.value = "";

  if (passwordForm.value.newPassword !== passwordForm.value.confirmPassword) {
    passwordError.value = "New password and confirmation do not match";
    return;
  }

  if (isSelf.value) {
    if (!passwordForm.value.currentPassword || !passwordForm.value.newPassword) {
      passwordError.value = "Please fill in all password fields";
      return;
    }
  } else {
    if (!passwordForm.value.newPassword) {
      passwordError.value = "Please enter a new password";
      return;
    }
  }

  savingPassword.value = true;
  try {
    if (isSelf.value) {
      await auth.changePassword({
        currentPassword: passwordForm.value.currentPassword,
        newPassword: passwordForm.value.newPassword,
      });
    } else {
      await updateUser(userId.value!, {
        name: profileForm.value.name,
        email: profileForm.value.email,
        password: passwordForm.value.newPassword,
        roleIds: profileForm.value.roleIds,
        isActive: profileForm.value.isActive,
      });
    }
    passwordChanged.value = true;
    toast.success(isSelf.value ? "Password changed" : "Password updated");
    passwordForm.value = { currentPassword: "", newPassword: "", confirmPassword: "" };
    setTimeout(() => { passwordChanged.value = false; }, 2000);
  } catch (e: unknown) {
    passwordError.value = e instanceof Error ? e.message : "Failed to change password";
    toast.error("Password update failed", passwordError.value);
  } finally {
    savingPassword.value = false;
  }
}

async function onAvatarUpload(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;
  uploadingAvatar.value = true;
  avatarError.value = "";
  try {
    await auth.uploadAvatar(file);
    userPhotoUrl.value = auth.user?.photoUrl || null;
    toast.success("Profile photo updated");
  } catch (e: unknown) {
    avatarError.value = e instanceof Error ? e.message : "Failed to upload photo";
    toast.error("Upload failed", avatarError.value);
  } finally {
    uploadingAvatar.value = false;
    (event.target as HTMLInputElement).value = "";
  }
}

async function onRemoveAvatar() {
  const allowed = await confirmDialog.confirm({
    title: "Remove profile photo?",
    message: "Your avatar will be removed from the account.",
    confirmText: "Remove",
    destructive: true,
  });
  if (!allowed) return;
  avatarError.value = "";
  try {
    await auth.removeAvatar();
    userPhotoUrl.value = null;
    toast.info("Profile photo removed");
  } catch (e: unknown) {
    avatarError.value = e instanceof Error ? e.message : "Failed to remove photo";
    toast.error("Remove failed", avatarError.value);
  }
}

watch(
  () => profileForm.value.userLevel,
  () => {
    if (isAgentOrUserSelf.value) return;
    if (!userLevelCanHaveManagedAgents(coerceUserLevel(profileForm.value.userLevel))) {
      profileForm.value.managedAgentIds = [];
      clearCustomerAgentState();
    }
  },
  { flush: "post" },
);

watch(
  () => profileForm.value.customerIds,
  async (ids, prev) => {
    if (skipCustomerIdsWatch.value) return;
    if (isAgentOrUserSelf.value) return;
    if (!userLevelCanHaveManagedAgents(profileForm.value.userLevel)) return;
    const normIds = normalizeCustomerIds(ids);
    const idSet = new Set(normIds);
    const newMap = { ...customerAgentMap.value };
    for (const k of Object.keys(newMap)) {
      const num = Number(k);
      if (!idSet.has(num)) delete newMap[num];
    }
    for (const id of normIds) {
      if (newMap[id] === undefined) newMap[id] = [];
    }
    customerAgentMap.value = newMap;

    const ap = { ...agentPicklists.value };
    const pe = { ...picklistError.value };
    const pl = { ...picklistLoading.value };
    for (const k of Object.keys(ap)) {
      const num = Number(k);
      if (!idSet.has(num)) {
        delete ap[num];
        delete pe[num];
        delete pl[num];
      }
    }
    agentPicklists.value = ap;
    picklistError.value = pe;
    picklistLoading.value = pl;

    const oldSet = new Set(normalizeCustomerIds(prev ?? []));
    for (const id of normIds) {
      if (!oldSet.has(id)) await loadPicklistForCustomer(id);
    }
  },
  { deep: true },
);

watch(() => route.params.id, load);
onMounted(() => {
  document.addEventListener("click", onDocumentClickCloseUserLevel);
  load();
});
onUnmounted(() => {
  document.removeEventListener("click", onDocumentClickCloseUserLevel);
});
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-7xl space-y-4">
      <!-- ───── Page Header ───── -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <router-link
            to="/admin/platform/identity/users"
            class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700"
            v-if="!isSelf"
          >
            <ArrowLeft class="h-4 w-4" />
          </router-link>
          <h1 class="page-title">{{ pageTitle }}</h1>
        </div>
      </div>

      <div v-if="!loading" class="grid gap-4 lg:grid-cols-[1fr_280px]">
        <!-- ═══════ LEFT COLUMN ═══════ -->
        <div class="space-y-4">
          <!-- ── User Information ── -->
          <article class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
              <User class="h-4 w-4 text-violet-600" />
              <h2 class="text-sm font-semibold text-slate-900">{{ isNew ? 'User Details' : 'Profile Information' }}</h2>
            </div>
            <div class="p-4">
              <div v-if="profileError" class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ profileError }}
              </div>
              <div v-if="profileSaved" class="mb-3 flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700">
                <CheckCircle2 class="h-4 w-4" />
                Saved successfully
              </div>
              <div class="grid gap-3 md:grid-cols-2">
                <div class="space-y-1.5">
                  <label class="text-sm font-medium text-slate-700">Full Name</label>
                  <input
                    v-model="profileForm.name"
                    type="text"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
                    placeholder="Full name"
                  />
                </div>
                <div class="space-y-1.5">
                  <label class="text-sm font-medium text-slate-700">Email Address</label>
                  <input
                    v-model="profileForm.email"
                    type="email"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
                    placeholder="user@example.com"
                  />
                </div>
                <!-- User Level - hidden for agent/user viewing own profile -->
                <div v-if="!isAgentOrUserSelf" class="space-y-1.5">
                  <label class="text-sm font-medium text-slate-700">User Level</label>
                  <p class="mb-1 text-xs text-slate-500">
                    Labels come from the <strong class="font-medium text-slate-600">settings</strong> row
                    <code class="rounded bg-slate-100 px-1 text-[11px]">lookupUserLevel</code> (same JSON as Settings → Lookups → User level, or edit via Database explorer).
                    Only tiers you may assign appear here (from your role); codes
                    <strong class="font-medium text-slate-600">0–5</strong> map to
                    <code class="text-[11px]">super_admin</code> … <code class="text-[11px]">secondary_user</code>.
                    After changing lookups in Settings, click <strong class="font-medium">Save Lookups</strong>.
                  </p>
                  <div ref="userLevelDropdownRoot" class="relative">
                    <button
                      type="button"
                      class="flex w-full items-center gap-3 rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-sm shadow-sm transition-colors hover:border-slate-400 focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
                      @click.stop="userLevelDropdownOpen = !userLevelDropdownOpen"
                    >
                      <span
                        class="inline-flex min-w-[2.25rem] shrink-0 justify-center rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs font-medium tabular-nums text-slate-700"
                      >
                        {{ selectedUserLevelOption?.code ?? "—" }}
                      </span>
                      <span class="min-w-0 flex-1 truncate text-slate-800">{{ selectedUserLevelOption?.desc ?? "—" }}</span>
                      <ChevronDown
                        class="h-4 w-4 shrink-0 text-slate-400 transition-transform"
                        :class="userLevelDropdownOpen ? 'rotate-180' : ''"
                      />
                    </button>
                    <ul
                      v-show="userLevelDropdownOpen"
                      class="absolute z-40 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
                      role="listbox"
                      @click.stop
                    >
                      <li v-for="opt in userLevelSelectOptions" :key="opt.optionId" role="option">
                        <button
                          type="button"
                          class="flex w-full items-center gap-3 px-3 py-2.5 text-left text-sm transition-colors hover:bg-slate-50"
                          :class="
                            selectedUserLevelOption?.optionId === opt.optionId
                              ? 'bg-violet-50 text-violet-900'
                              : 'text-slate-800'
                          "
                          @click="pickUserLevel(opt)"
                        >
                          <span
                            class="inline-flex min-w-[2.25rem] shrink-0 justify-center rounded-md px-2 py-0.5 font-mono text-xs tabular-nums"
                            :class="
                              selectedUserLevelOption?.optionId === opt.optionId
                                ? 'bg-violet-200/80 text-violet-900'
                                : 'bg-slate-100 text-slate-600'
                            "
                          >
                            {{ opt.code }}
                          </span>
                          <span class="min-w-0 flex-1">{{ opt.desc }}</span>
                          <Check
                            v-if="selectedUserLevelOption?.optionId === opt.optionId"
                            class="h-4 w-4 shrink-0 text-violet-600"
                          />
                        </button>
                      </li>
                    </ul>
                  </div>
                </div>
                <div v-else class="space-y-1.5">
                  <label class="text-sm font-medium text-slate-700">User Level</label>
                  <div class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                    <span
                      class="inline-flex min-w-[2.25rem] shrink-0 justify-center rounded-md bg-white px-2 py-0.5 font-mono text-xs font-medium tabular-nums text-slate-600 shadow-sm"
                    >
                      {{ selectedUserLevelOption?.code ?? "—" }}
                    </span>
                    <span class="min-w-0 flex-1">{{ selectedUserLevelOption?.desc ?? userLevelReadOnlyLabel }}</span>
                  </div>
                </div>
                <div v-if="!isAgentOrUserSelf" class="space-y-1.5">
                  <label class="text-sm font-medium text-slate-700">Jenis Pengguna</label>
                  <p class="mb-1 text-xs text-slate-500">
                    Pilihan ini datang dari <code class="rounded bg-slate-100 px-1 text-[11px]">lookupUserJenisPengguna</code>
                    dalam Settings.
                  </p>
                  <input
                    v-model="userJenisPenggunaSearch"
                    type="text"
                    class="mb-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
                    placeholder="Search jenis pengguna..."
                  />
                  <div class="space-y-2 rounded-lg border border-slate-300 p-3">
                    <label
                      v-for="opt in visibleUserJenisPenggunaOptions"
                      :key="`jp-${opt.code}`"
                      class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm transition-colors"
                      :class="
                        profileForm.userJenisPengguna === opt.code
                          ? 'border-violet-300 bg-violet-50 text-violet-800'
                          : 'border-slate-200 hover:bg-slate-50'
                      "
                    >
                      <input
                        v-model="profileForm.userJenisPengguna"
                        type="radio"
                        :value="opt.code"
                        name="user-jenis-pengguna"
                        class="border-slate-300 text-violet-600"
                      />
                      <span class="inline-flex min-w-[2.25rem] justify-center rounded bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-700">
                        {{ opt.code }}
                      </span>
                      <span>{{ opt.desc }}</span>
                    </label>
                    <div class="mt-1 flex items-center justify-between">
                      <button
                        v-if="userJenisPenggunaHasMore && userJenisPenggunaSearch.trim() === ''"
                        type="button"
                        class="text-xs text-violet-600 underline hover:text-violet-700"
                        @click="userJenisPenggunaShowAll = !userJenisPenggunaShowAll"
                      >
                        {{ userJenisPenggunaShowAll ? "Show less" : "More" }}
                      </button>
                      <span v-else></span>
                      <button
                        type="button"
                        class="text-xs text-slate-500 underline hover:text-slate-700"
                        @click="profileForm.userJenisPengguna = ''"
                      >
                        Clear selection
                      </button>
                    </div>
                  </div>
                </div>
                <!-- Roles - hidden for agent/user viewing own profile -->
                <div v-if="showRolesSection" class="col-span-full w-full min-w-0 space-y-1.5">
                  <label class="text-sm font-medium text-slate-700">Roles</label>
                  <div class="flex flex-wrap gap-2 rounded-lg border border-slate-300 p-3">
                    <label
                      v-for="r in roles"
                      :key="r.id"
                      class="inline-flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-1.5 text-sm transition-colors"
                      :class="profileForm.roleIds.includes(r.id) ? 'border-violet-300 bg-violet-50 text-violet-700' : 'border-slate-200 hover:bg-slate-50'"
                    >
                      <input
                        v-model="profileForm.roleIds"
                        type="checkbox"
                        :value="r.id"
                        class="rounded border-slate-300 text-violet-600"
                      />
                      {{ r.name }}
                    </label>
                  </div>
                </div>
                <!-- Customers: nested agent picklists under each selected customer (L0–L3); read-only for agent/user viewing own profile -->
                <div class="col-span-full w-full min-w-0 space-y-1.5">
                  <label class="text-sm font-medium text-slate-700">Customers</label>
                  <div
                    v-if="canEditCustomers"
                    class="max-h-96 overflow-y-auto rounded-lg border border-slate-300 p-3"
                  >
                    <p
                      v-if="!isAgentOrUserSelf && userLevelCanHaveManagedAgents(profileForm.userLevel)"
                      class="mb-3 text-xs text-slate-500"
                    >
                      Tandakan pelanggan. Untuk setiap yang ditanda, pilih ejen di bawahnya (ejen mesti ditanda dalam senarai
                      <strong>Ejen (dilantik)</strong> di bawah, dan berkongsi pelanggan yang sama pada profil ejen).
                    </p>
                    <div
                      v-for="c in customers"
                      :key="'cust-' + c.id"
                      class="border-b border-slate-100 py-2 last:border-b-0 last:pb-0"
                    >
                      <label class="flex cursor-pointer items-start gap-2 text-sm">
                        <input
                          v-model="profileForm.customerIds"
                          type="checkbox"
                          :value="c.id"
                          class="mt-0.5 shrink-0 rounded border-slate-300 text-violet-600"
                        />
                        <span class="font-medium text-slate-800">{{ c.customerCode }} — {{ c.customerName }}</span>
                      </label>
                      <div
                        v-if="
                          selectedCustomerIdSet.has(Number(c.id)) &&
                          !isAgentOrUserSelf &&
                          userLevelCanHaveManagedAgents(profileForm.userLevel)
                        "
                        class="ml-7 mt-2 space-y-1.5 border-l-2 border-violet-300 pl-3"
                      >
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-violet-800">Ejen untuk pelanggan ini</p>
                        <p
                          v-if="picklistError[c.id]"
                          class="rounded border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-900"
                        >
                          {{ picklistError[c.id] }}
                        </p>
                        <p v-if="picklistLoading[c.id] && !(agentPicklists[c.id]?.length)" class="text-xs text-slate-500">
                          Memuat senarai ejen…
                        </p>
                        <div v-else class="max-h-36 overflow-y-auto rounded border border-slate-200 bg-white p-2">
                          <label
                            v-for="a in agentPicklists[c.id] || []"
                            :key="'ag-' + c.id + '-' + a.id"
                            class="flex cursor-pointer items-center gap-2 py-1 text-sm"
                          >
                            <input
                              type="checkbox"
                              class="rounded border-slate-300 text-violet-600"
                              :checked="isCustomerAgentSelected(Number(c.id), a.id)"
                              @change="
                                toggleCustomerAgent(
                                  Number(c.id),
                                  a.id,
                                  ($event.target as HTMLInputElement).checked,
                                )
                              "
                            />
                            {{ a.name }} — {{ a.email }}
                          </label>
                          <p
                            v-if="!picklistLoading[c.id] && !(agentPicklists[c.id]?.length)"
                            class="py-2 text-xs text-slate-400"
                          >
                            Tiada ejen untuk pelanggan ini. Pastikan ejen berkongsi pelanggan pada profil ejen dan ditanda dalam
                            senarai Ejen (dilantik) di bawah.
                          </p>
                        </div>
                      </div>
                    </div>
                    <p v-if="customers.length === 0" class="py-2 text-xs text-slate-400">No customers.</p>
                  </div>
                  <div
                    v-else
                    class="rounded-lg border border-slate-200 bg-slate-50 p-3"
                  >
                    <template v-if="profileForm.customerIds.length > 0">
                      <span
                        v-for="id in profileForm.customerIds"
                        :key="id"
                        class="mr-2 mb-1 inline-block rounded bg-slate-200 px-2 py-0.5 text-sm text-slate-700"
                      >
                        {{ customers.find((c) => c.id === id)?.customerCode }} — {{ customers.find((c) => c.id === id)?.customerName }}
                      </span>
                    </template>
                    <p v-else class="text-sm text-slate-500">No customers assigned.</p>
                  </div>
                </div>
                <!-- Ejen global (bawah Customers): beberapa ejen dilapor kepada pengguna ini — Level 0–3 -->
                <div
                  v-if="
                    !isAgentOrUserSelf &&
                    userLevelCanHaveManagedAgents(coerceUserLevel(profileForm.userLevel))
                  "
                  class="col-span-full w-full min-w-0 border-t border-slate-200 pt-3"
                >
                  <UserManagedAgentsField
                    v-model="profileForm.managedAgentIds"
                    :target-user-level="profileForm.userLevel"
                    :exclude-user-id="userId"
                  />
                </div>
                <!-- Catatan (notes) — below Customers -->
                <div class="col-span-full w-full min-w-0 space-y-1.5">
                  <label class="text-sm font-medium text-slate-700">Catatan</label>
                  <textarea
                    v-if="canEditCustomers"
                    v-model="profileForm.notes"
                    rows="4"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
                    placeholder="Nota dalaman tentang pengguna ini (pilihan)"
                  />
                  <div
                    v-else
                    class="min-h-[4.5rem] rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 whitespace-pre-wrap"
                  >
                    {{ profileForm.notes || "—" }}
                  </div>
                </div>
                <!-- Active - hidden for agent/user viewing own profile -->
                <div v-if="!isAgentOrUserSelf" class="flex items-end pb-1">
                  <label class="relative inline-flex cursor-pointer items-center gap-3">
                    <input v-model="profileForm.isActive" type="checkbox" class="peer sr-only" />
                    <div class="h-5 w-9 rounded-full bg-slate-200 after:absolute after:left-[2px] after:top-[2px] after:h-4 after:w-4 after:rounded-full after:bg-white after:shadow-sm after:transition-all peer-checked:bg-violet-600 peer-checked:after:translate-x-full" />
                    <span class="text-sm text-slate-700">Active</span>
                  </label>
                </div>
              </div>

              <!-- Password fields for new user (inline) -->
              <div v-if="isNew" class="mt-3 grid gap-3 md:grid-cols-2">
                <div class="space-y-1.5">
                  <label class="text-sm font-medium text-slate-700">Password</label>
                  <input
                    v-model="passwordForm.newPassword"
                    type="password"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
                    placeholder="••••••••"
                  />
                </div>
                <div class="space-y-1.5">
                  <label class="text-sm font-medium text-slate-700">Confirm Password</label>
                  <input
                    v-model="passwordForm.confirmPassword"
                    type="password"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
                    placeholder="••••••••"
                  />
                </div>
              </div>

              <div class="mt-4 flex justify-end">
                <button
                  class="flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-slate-800 disabled:opacity-50"
                  :disabled="savingProfile || !profileForm.name || !profileForm.email"
                  @click="saveProfile"
                >
                  <Save class="h-3.5 w-3.5" />
                  {{ isNew ? (savingProfile ? 'Creating...' : 'Create User') : (savingProfile ? 'Saving...' : 'Save Changes') }}
                </button>
              </div>
            </div>
          </article>

          <!-- ── Change Password (edit mode only) ── -->
          <article v-if="!isNew" class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
              <Lock class="h-4 w-4 text-amber-600" />
              <h2 class="text-sm font-semibold text-slate-900">{{ isSelf ? 'Change Password' : 'Set New Password' }}</h2>
            </div>
            <div class="p-4">
              <div v-if="passwordError" class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ passwordError }}
              </div>
              <div v-if="passwordChanged" class="mb-3 flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700">
                <CheckCircle2 class="h-4 w-4" />
                Password {{ isSelf ? 'changed' : 'updated' }} successfully
              </div>
              <div class="space-y-3">
                <!-- Current password (self only) -->
                <div v-if="isSelf" class="space-y-1.5">
                  <label class="text-sm font-medium text-slate-700">Current Password</label>
                  <input
                    v-model="passwordForm.currentPassword"
                    type="password"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                  <div class="space-y-1.5">
                    <label class="text-sm font-medium text-slate-700">New Password</label>
                    <input
                      v-model="passwordForm.newPassword"
                      type="password"
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
                    />
                  </div>
                  <div class="space-y-1.5">
                    <label class="text-sm font-medium text-slate-700">Confirm New Password</label>
                    <input
                      v-model="passwordForm.confirmPassword"
                      type="password"
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200"
                    />
                  </div>
                </div>
              </div>
              <div class="mt-4 flex justify-end">
                <button
                  class="flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-slate-800 disabled:opacity-50"
                  :disabled="savingPassword"
                  @click="savePassword"
                >
                  <Lock class="h-3.5 w-3.5" />
                  {{ savingPassword ? 'Saving...' : (isSelf ? 'Change Password' : 'Set Password') }}
                </button>
              </div>
            </div>
          </article>
        </div>

        <!-- ═══════ RIGHT COLUMN ═══════ -->
        <div class="space-y-4">
          <!-- ── Profile Photo (self only) ── -->
          <article v-if="isSelf" class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
              <Camera class="h-4 w-4 text-indigo-600" />
              <h2 class="text-sm font-semibold text-slate-900">Profile Photo</h2>
            </div>
            <div class="flex flex-col items-center p-4">
              <div v-if="avatarError" class="mb-3 w-full rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ avatarError }}
              </div>
              <!-- Avatar preview -->
              <div class="mb-4">
                <img
                  v-if="auth.user?.photoUrl"
                  :src="resolveUrl(auth.user.photoUrl)"
                  alt="Profile photo"
                  class="h-24 w-24 rounded-full border-2 border-slate-200 object-cover"
                />
                <div
                  v-else
                  class="flex h-24 w-24 items-center justify-center rounded-full bg-gradient-to-br from-violet-600 to-indigo-600 text-2xl font-semibold text-white"
                >
                  {{ userInitials }}
                </div>
              </div>
              <!-- Upload / Remove -->
              <div class="flex items-center gap-2">
                <label
                  class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50"
                  :class="uploadingAvatar ? 'opacity-50 pointer-events-none' : ''"
                >
                  <Upload class="h-4 w-4" />
                  {{ uploadingAvatar ? 'Uploading...' : 'Upload' }}
                  <input
                    type="file"
                    accept="image/*"
                    class="hidden"
                    :disabled="uploadingAvatar"
                    @change="onAvatarUpload"
                  />
                </label>
                <button
                  v-if="auth.user?.photoUrl"
                  class="flex items-center gap-2 rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-600 transition-colors hover:bg-red-50"
                  @click="onRemoveAvatar"
                >
                  <Trash2 class="h-4 w-4" />
                  Remove
                </button>
              </div>
              <p class="mt-3 text-center text-xs text-slate-400">JPG, PNG or GIF. Max 2MB.</p>
            </div>
          </article>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
