<script setup lang="ts">
import { onMounted, ref } from "vue";
import {
  Shield,
  Plus,
  Pencil,
  Trash2,
  Save,
  X,
} from "lucide-vue-next";

import AdminLayout from "@/layouts/AdminLayout.vue";
import { ensureCsrfCookie } from "@/api/client";
import { listRoles, createRole, updateRole, deleteRole } from "@/api/cms";
import { buildRoleMenuTree, getPermissionLabelsForMenuId } from "@/config/role-menu-tree";
import type { MenuTreeNode } from "@/config/role-menu-tree";
import { useConfirmDialog } from "@/composables/useConfirmDialog";
import { useToast } from "@/composables/useToast";
import { useAuthStore } from "@/stores/auth";
import type { Role, RoleInput } from "@/types";

const roles = ref<Role[]>([]);
const showForm = ref(false);
const editingId = ref<number | null>(null);
const saving = ref(false);
const confirmDialog = useConfirmDialog();
const toast = useToast();
const auth = useAuthStore();

const menuTree = buildRoleMenuTree();

const availablePermissions = [
  "posts.view", "posts.create", "posts.edit", "posts.delete",
  "pages.view", "pages.create", "pages.edit", "pages.delete",
  "media.view", "media.upload", "media.delete",
  "users.view", "users.create", "users.edit", "users.delete",
  "roles.view", "roles.create", "roles.edit", "roles.delete",
  "settings.view", "settings.edit",
  "menus.view", "menus.edit",
  "audit.read",
  "knowledge.view", "knowledge.manage",
  "chat.use", "chat.admin",
  "customers.view", "customers.create", "customers.edit", "customers.delete",
  "notifications.admin",
  "tickets.view", "tickets.create", "tickets.edit", "tickets.delete", "tickets.assign", "tickets.respond",
];

const form = ref<RoleInput>({
  name: "",
  description: "",
  permissions: [],
  menuAccess: [],
});

async function load() {
  const res = await listRoles();
  roles.value = res.data;
}

function startNew() {
  editingId.value = null;
  form.value = { name: "", description: "", permissions: [], menuAccess: [] };
  showForm.value = true;
}

function startEdit(role: Role) {
  editingId.value = role.id;
  form.value = {
    name: role.name,
    description: role.description,
    permissions: [...role.permissions],
    menuAccess: role.menuAccess ? [...role.menuAccess] : [],
  };
  showForm.value = true;
}

function getMenuAccess(): string[] {
  return form.value.menuAccess ?? [];
}

function isMenuChecked(id: string): boolean {
  return getMenuAccess().includes(id);
}

function toggleMenuAccess(id: string) {
  const arr = [...(form.value.menuAccess ?? [])];
  const idx = arr.indexOf(id);
  if (idx >= 0) {
    arr.splice(idx, 1);
  } else {
    arr.push(id);
  }
  form.value.menuAccess = arr;
}

function toggleNodeAndDescendants(node: MenuTreeNode, checked: boolean) {
  // Always copy — mutating the same array ref can skip Vue reactivity and confuse the payload sent on save.
  const arr = [...(form.value.menuAccess ?? [])];
  const ids = collectIds(node);
  if (checked) {
    for (const id of ids) {
      if (!arr.includes(id)) arr.push(id);
    }
  } else {
    for (const id of ids) {
      const i = arr.indexOf(id);
      if (i >= 0) arr.splice(i, 1);
    }
  }
  form.value.menuAccess = arr;
}

function collectIds(node: MenuTreeNode): string[] {
  const ids = [node.id];
  if (node.children?.length) {
    for (const c of node.children) ids.push(...collectIds(c));
  }
  return ids;
}

function toggleGroupAll(group: MenuTreeNode, checked: boolean) {
  if (!group.children?.length) {
    toggleMenuAccess(group.id);
    return;
  }
  toggleNodeAndDescendants(group, checked);
}

function isGroupAllChecked(group: MenuTreeNode): boolean {
  const ids = collectIds(group);
  const arr = getMenuAccess();
  return ids.every((id) => arr.includes(id));
}

function isGroupSomeChecked(group: MenuTreeNode): boolean {
  const ids = collectIds(group);
  const arr = getMenuAccess();
  return ids.some((id) => arr.includes(id));
}

function cancelForm() {
  showForm.value = false;
  editingId.value = null;
}

function togglePermission(perm: string) {
  const next = [...form.value.permissions];
  const idx = next.indexOf(perm);
  if (idx >= 0) {
    next.splice(idx, 1);
  } else {
    next.push(perm);
  }
  form.value.permissions = next;
}

function permissionGroup(perm: string) {
  return perm.split(".")[0];
}

const groupedPermissions = availablePermissions.reduce<Record<string, string[]>>((acc, p) => {
  const group = permissionGroup(p);
  if (!acc[group]) acc[group] = [];
  acc[group].push(p);
  return acc;
}, {});

async function save() {
  saving.value = true;
  const wasEdit = editingId.value !== null;
  const roleId = editingId.value;
  try {
    await ensureCsrfCookie(true);
    if (wasEdit && roleId !== null) {
      await updateRole(roleId, {
        name: form.value.name.trim(),
        description: (form.value.description ?? "").trim(),
        permissions: [...form.value.permissions],
        menuAccess: [...(form.value.menuAccess ?? [])],
      });
    } else {
      await createRole({
        name: form.value.name.trim(),
        description: (form.value.description ?? "").trim(),
        permissions: [...form.value.permissions],
        menuAccess: [...(form.value.menuAccess ?? [])],
      });
    }
    await load();
    showForm.value = false;
    editingId.value = null;
    toast.success(wasEdit ? "Role updated" : "Role created");
    // Refresh auth so sidebar menu updates immediately (user may have this role)
    await auth.refreshUser();
  } catch (e) {
    toast.error("Save failed", e instanceof Error ? e.message : "Unable to save role.");
  } finally {
    saving.value = false;
  }
}

async function remove(id: number) {
  const allowed = await confirmDialog.confirm({
    title: "Delete role?",
    message: "This action cannot be undone.",
    confirmText: "Delete",
    destructive: true,
  });
  if (!allowed) return;
  try {
    await deleteRole(id);
    await load();
    toast.success("Role deleted");
  } catch (e) {
    toast.error("Delete failed", e instanceof Error ? e.message : "Unable to delete role.");
  }
}

onMounted(load);
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-7xl space-y-4">
      <!-- ───── Hero Header ───── -->
      <div class="flex items-center justify-between">
        <h1 class="page-title">Roles & Permissions</h1>
        <button
          class="flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-1.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-slate-800"
          @click="startNew"
        >
          <Plus class="h-4 w-4" />
          Add Role
        </button>
      </div>

      <!-- ───── Form Card ───── -->
      <article v-if="showForm" class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
          <Pencil class="h-4 w-4 text-violet-600" />
          <h2 class="text-sm font-semibold text-slate-900">{{ editingId ? 'Edit Role' : 'New Role' }}</h2>
        </div>
        <div class="space-y-3 p-4">
          <div class="grid gap-3 md:grid-cols-2">
            <div class="space-y-1.5">
              <label class="text-sm font-medium text-slate-700">Role Name</label>
              <input v-model="form.name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="e.g. Editor" />
            </div>
            <div class="space-y-1.5">
              <label class="text-sm font-medium text-slate-700">Description</label>
              <input v-model="form.description" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm transition-colors focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="Brief description" />
            </div>
          </div>

          <!-- Permissions Grid -->
          <div class="space-y-1.5">
            <label class="text-sm font-medium text-slate-700">Permissions</label>
            <div class="rounded-lg border border-slate-200 divide-y divide-slate-100">
              <div v-for="(perms, group) in groupedPermissions" :key="group" class="px-4 py-3">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ group }}</p>
                <div class="flex flex-wrap gap-2">
                  <button
                    v-for="perm in perms"
                    :key="perm"
                    class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors"
                    :class="form.permissions.includes(perm) ? 'bg-violet-100 text-violet-700 ring-1 ring-violet-300' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'"
                    @click="togglePermission(perm)"
                  >
                    {{ perm.split('.')[1] }}
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Role to Menu -->
          <div class="space-y-1.5">
            <label class="text-sm font-medium text-slate-700">Role to Menu</label>
            <p class="text-xs text-slate-500">Select which menu items this role can access. Leave empty for full access. Items with permission badge need that permission (auto-granted when menu selected).</p>
            <p v-if="auth.user?.userLevel === 'super_admin'" class="rounded-md bg-amber-50 px-2 py-1.5 text-xs text-amber-800">
              <strong>Note:</strong> You are Super Admin — you always see the full menu. To test role-based menu, log in with a user who has this role.
            </p>
            <div class="max-h-64 overflow-y-auto rounded-lg border border-slate-200 divide-y divide-slate-100">
              <div v-for="group in menuTree" :key="group.id" class="px-4 py-3">
                <label class="flex cursor-pointer items-center gap-2 py-1">
                  <input
                    type="checkbox"
                    :checked="isGroupAllChecked(group)"
                    :indeterminate.prop="isGroupSomeChecked(group) && !isGroupAllChecked(group)"
                    class="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                    @change="toggleGroupAll(group, ($event.target as HTMLInputElement).checked)"
                  />
                  <span class="text-sm font-semibold text-slate-800">{{ group.label }}</span>
                </label>
                <div v-if="group.children?.length" class="ml-6 mt-2 space-y-2">
                  <div v-for="item in group.children" :key="item.id" class="space-y-1">
                    <label class="flex cursor-pointer items-center gap-2 py-0.5">
                      <input
                        type="checkbox"
                        :checked="isMenuChecked(item.id)"
                        class="h-3.5 w-3.5 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                        @change="toggleMenuAccess(item.id)"
                      />
                      <span class="text-sm text-slate-700">{{ item.label }}</span>
                      <span
                        v-if="getPermissionLabelsForMenuId(item.id)"
                        class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800"
                        :title="`Permission: ${getPermissionLabelsForMenuId(item.id)}`"
                      >
                        {{ getPermissionLabelsForMenuId(item.id) }}
                      </span>
                    </label>
                    <div v-if="item.children?.length" class="ml-5 space-y-0.5">
                      <template v-for="child in item.children" :key="child.id">
                        <label class="flex cursor-pointer items-center gap-2 py-0.5">
                          <input
                            type="checkbox"
                            :checked="isMenuChecked(child.id)"
                            class="h-3.5 w-3.5 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                            @change="toggleMenuAccess(child.id)"
                          />
                          <span class="text-xs text-slate-600">{{ child.label }}</span>
                          <span
                            v-if="getPermissionLabelsForMenuId(child.id)"
                            class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800"
                            :title="`Permission: ${getPermissionLabelsForMenuId(child.id)}`"
                          >
                            {{ getPermissionLabelsForMenuId(child.id) }}
                          </span>
                        </label>
                        <div v-if="child.children?.length" class="ml-4 space-y-0.5">
                          <label
                            v-for="gc in child.children"
                            :key="gc.id"
                            class="flex cursor-pointer items-center gap-2 py-0.5"
                          >
                            <input
                              type="checkbox"
                              :checked="isMenuChecked(gc.id)"
                              class="h-3 w-3 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                              @change="toggleMenuAccess(gc.id)"
                            />
                            <span class="text-xs text-slate-500">{{ gc.label }}</span>
                            <span
                              v-if="getPermissionLabelsForMenuId(gc.id)"
                              class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800"
                              :title="`Permission: ${getPermissionLabelsForMenuId(gc.id)}`"
                            >
                              {{ getPermissionLabelsForMenuId(gc.id) }}
                            </span>
                          </label>
                        </div>
                      </template>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-3 border-t border-slate-100 pt-3">
            <button
              class="flex items-center gap-2 rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-slate-800 disabled:opacity-50"
              :disabled="saving || !form.name"
              @click="save"
            >
              <Save class="h-4 w-4" />
              {{ editingId ? 'Update' : 'Create' }}
            </button>
            <button class="flex items-center gap-2 rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50" @click="cancelForm">
              <X class="h-4 w-4" />
              Cancel
            </button>
          </div>
        </div>
      </article>

      <!-- ───── Roles List ───── -->
      <article class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
          <Shield class="h-4 w-4 text-amber-600" />
          <h2 class="text-sm font-semibold text-slate-900">All Roles</h2>
        </div>
        <div class="divide-y divide-slate-100">
          <div v-for="role in roles" :key="role.id" class="flex items-center justify-between px-4 py-2.5 transition-colors hover:bg-slate-50">
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium text-slate-900">{{ role.name }}</p>
              <p class="mt-0.5 text-xs text-slate-400">{{ role.description }}</p>
              <div v-if="role.permissions.length > 0" class="mt-2 flex flex-wrap gap-1">
                <span
                  v-for="perm in role.permissions.slice(0, 6)"
                  :key="perm"
                  class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500"
                >{{ perm }}</span>
                <span v-if="role.permissions.length > 6" class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-400">
                  +{{ role.permissions.length - 6 }} more
                </span>
              </div>
              <div v-if="role.menuAccess?.length" class="mt-1.5 text-xs text-slate-500">
                Menu: {{ role.menuAccess.length }} item(s)
              </div>
            </div>
            <div class="flex shrink-0 items-center gap-1.5 ml-4">
              <button class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700" title="Edit" @click="startEdit(role)">
                <Pencil class="h-3.5 w-3.5" />
              </button>
              <button class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-rose-50 hover:text-rose-600" title="Delete" @click="remove(role.id)">
                <Trash2 class="h-3.5 w-3.5" />
              </button>
            </div>
          </div>
          <div v-if="roles.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">
            No roles configured yet.
          </div>
        </div>
      </article>
    </div>
  </AdminLayout>
</template>
