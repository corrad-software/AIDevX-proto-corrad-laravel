<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { Database, Pencil, Plus, RefreshCw, Search, Trash2 } from "lucide-vue-next";

import AdminLayout from "@/layouts/AdminLayout.vue";
import {
  createDatabaseRow,
  deleteDatabaseRow,
  getDatabaseTableRows,
  getDatabaseTableSchema,
  listDatabaseTables,
  updateDatabaseRow,
} from "@/api/cms";
import { useConfirmDialog } from "@/composables/useConfirmDialog";
import { useToast } from "@/composables/useToast";
import type { DatabaseColumnInfo } from "@/types";

const toast = useToast();
const confirmDialog = useConfirmDialog();

const tables = ref<string[]>([]);
const tableFilter = ref("");
const selectedTable = ref<string | null>(null);
const columns = ref<DatabaseColumnInfo[]>([]);
const primaryKeyColumns = ref<string[]>([]);
const rows = ref<Record<string, unknown>[]>([]);
const meta = ref<{ page: number; limit: number; total: number; totalPages: number } | null>(null);

const loadingTables = ref(false);
const loadingData = ref(false);
const forbidden = ref(false);

const page = ref(1);
const limit = ref(25);
const sortBy = ref<string | null>(null);
const sortDir = ref<"asc" | "desc">("asc");

const addOpen = ref(false);
const editOpen = ref(false);
const formValues = ref<Record<string, string>>({});
const editingRow = ref<Record<string, unknown> | null>(null);

const filteredTables = computed(() => {
  const q = tableFilter.value.trim().toLowerCase();
  if (!q) return tables.value;
  return tables.value.filter((t) => t.toLowerCase().includes(q));
});

const isWritable = computed(
  () => selectedTable.value !== null && selectedTable.value !== "migrations",
);

const canMutateRows = computed(() => isWritable.value && primaryKeyColumns.value.length > 0);

const displayColumnNames = computed(() => {
  if (columns.value.length) return columns.value.map((c) => c.name);
  const first = rows.value[0];
  return first ? Object.keys(first) : [];
});

function displayCell(v: unknown): string {
  if (v === null || v === undefined) return "—";
  if (typeof v === "object") return JSON.stringify(v);
  return String(v);
}

function toCamelCase(value: string): string {
  return value.replace(/_([a-z])/g, (_, c: string) => c.toUpperCase());
}

function rowValue(row: Record<string, unknown>, column: string): unknown {
  if (Object.prototype.hasOwnProperty.call(row, column)) {
    return row[column];
  }
  const camel = toCamelCase(column);
  if (Object.prototype.hasOwnProperty.call(row, camel)) {
    return row[camel];
  }
  return undefined;
}

function rowToForm(row: Record<string, unknown>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const name of displayColumnNames.value) {
    const val = rowValue(row, name);
    if (val === null || val === undefined) out[name] = "";
    else if (typeof val === "object") out[name] = JSON.stringify(val);
    else out[name] = String(val);
  }
  return out;
}

function emptyFormForInsert(): Record<string, string> {
  const out: Record<string, string> = {};
  for (const c of columns.value) {
    out[c.name] = "";
  }
  return out;
}

function parseFormToRow(mode: "insert" | "update"): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const c of columns.value) {
    if (mode === "update" && c.primaryKey) continue;
    const raw = (formValues.value[c.name] ?? "").trim();
    if (raw === "") {
      if (c.nullable) out[c.name] = null;
      else if (mode === "insert" && c.primaryKey) continue;
      else if (mode === "insert") continue;
      else continue;
    } else {
      out[c.name] = coerceValue(raw, c);
    }
  }
  return out;
}

function coerceValue(raw: string, col: DatabaseColumnInfo): unknown {
  if (raw === "null") return null;
  const t = col.type.toLowerCase();
  if (t.includes("int") || t.includes("decimal") || t.includes("float") || t.includes("double") || t.includes("numeric")) {
    const n = Number(raw);
    return Number.isNaN(n) ? raw : n;
  }
  if (t.includes("bool")) {
    const l = raw.toLowerCase();
    if (l === "true" || l === "1") return true;
    if (l === "false" || l === "0") return false;
  }
  if ((raw.startsWith("{") && raw.endsWith("}")) || (raw.startsWith("[") && raw.endsWith("]"))) {
    try {
      return JSON.parse(raw) as unknown;
    } catch {
      return raw;
    }
  }
  return raw;
}

function pickPrimaryKey(row: Record<string, unknown>): Record<string, unknown> {
  const pk: Record<string, unknown> = {};
  for (const c of primaryKeyColumns.value) {
    pk[c] = rowValue(row, c);
  }
  return pk;
}

async function loadTables() {
  forbidden.value = false;
  loadingTables.value = true;
  try {
    const res = await listDatabaseTables();
    tables.value = res.data.tables;
  } catch (e) {
    tables.value = [];
    const msg = e instanceof Error ? e.message : "";
    if (msg.toLowerCase().includes("forbidden") || msg.includes("403")) {
      forbidden.value = true;
    }
    toast.error("Failed to load tables", msg);
  } finally {
    loadingTables.value = false;
  }
}

async function loadTableData() {
  const t = selectedTable.value;
  if (!t) return;
  forbidden.value = false;
  loadingData.value = true;
  try {
    const [schemaRes, rowsRes] = await Promise.all([
      getDatabaseTableSchema(t),
      getDatabaseTableRows(t, {
        page: page.value,
        limit: limit.value,
        sortBy: sortBy.value ?? undefined,
        sortDir: sortDir.value,
      }),
    ]);
    columns.value = schemaRes.data.columns;
    primaryKeyColumns.value = schemaRes.data.primaryKeyColumns;
    rows.value = rowsRes.data.rows;
    meta.value = {
      page: rowsRes.meta.page as number,
      limit: rowsRes.meta.limit as number,
      total: rowsRes.meta.total as number,
      totalPages: rowsRes.meta.totalPages as number,
    };
  } catch (e) {
    columns.value = [];
    primaryKeyColumns.value = [];
    rows.value = [];
    meta.value = null;
    const msg = e instanceof Error ? e.message : "";
    if (msg.toLowerCase().includes("forbidden") || msg.includes("403")) {
      forbidden.value = true;
    }
    toast.error("Failed to load table", msg);
  } finally {
    loadingData.value = false;
  }
}

function selectTable(name: string) {
  if (selectedTable.value === name) return;
  selectedTable.value = name;
  page.value = 1;
  sortBy.value = null;
  sortDir.value = "asc";
  void loadTableData();
}

function toggleSort(col: string) {
  if (sortBy.value === col) {
    sortDir.value = sortDir.value === "asc" ? "desc" : "asc";
  } else {
    sortBy.value = col;
    sortDir.value = "asc";
  }
  page.value = 1;
  void loadTableData();
}

function openAdd() {
  formValues.value = emptyFormForInsert();
  addOpen.value = true;
}

function openEdit(row: Record<string, unknown>) {
  editingRow.value = row;
  formValues.value = rowToForm(row);
  editOpen.value = true;
}

async function saveAdd() {
  const t = selectedTable.value;
  if (!t) return;
  try {
    const row = parseFormToRow("insert");
    await createDatabaseRow(t, row);
    addOpen.value = false;
    toast.success("Row inserted");
    await loadTableData();
  } catch (e) {
    toast.error("Insert failed", e instanceof Error ? e.message : undefined);
  }
}

async function saveEdit() {
  const t = selectedTable.value;
  const row = editingRow.value;
  if (!t || !row) return;
  try {
    const primaryKey = pickPrimaryKey(row);
    const payload = parseFormToRow("update");
    await updateDatabaseRow(t, primaryKey, payload);
    editOpen.value = false;
    editingRow.value = null;
    toast.success("Row updated");
    await loadTableData();
  } catch (e) {
    toast.error("Update failed", e instanceof Error ? e.message : undefined);
  }
}

async function removeRow(row: Record<string, unknown>) {
  const t = selectedTable.value;
  if (!t) return;
  const ok = await confirmDialog.confirm({
    title: "Delete row?",
    message: "This cannot be undone. Foreign keys may block the delete.",
    confirmText: "Delete",
    destructive: true,
  });
  if (!ok) return;
  try {
    await deleteDatabaseRow(t, pickPrimaryKey(row));
    toast.success("Row deleted");
    await loadTableData();
  } catch (e) {
    toast.error("Delete failed", e instanceof Error ? e.message : undefined);
  }
}

function goPrevPage() {
  if (page.value <= 1) return;
  page.value -= 1;
  void loadTableData();
}

function goNextPage() {
  if (!meta.value || page.value >= meta.value.totalPages) return;
  page.value += 1;
  void loadTableData();
}

onMounted(() => {
  void loadTables();
});
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-[1600px] space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <Database class="h-6 w-6 text-slate-600" />
          <h1 class="page-title">Database</h1>
        </div>
        <p class="max-w-xl text-sm text-slate-500">
          Browse tables and rows on the app connection. Requires <code class="rounded bg-slate-100 px-1">database.manage</code>.
          Writes to <code class="rounded bg-slate-100 px-1">migrations</code> are blocked.
        </p>
      </div>

      <div v-if="forbidden" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        You do not have permission to use the database explorer.
      </div>

      <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
        <aside class="w-full shrink-0 rounded-lg border border-slate-200 bg-white shadow-sm lg:w-72 lg:self-start">
          <div class="flex items-center justify-between border-b border-slate-100 px-3 py-2">
            <div>
              <span class="text-sm font-semibold text-slate-900">Tables</span>
              <span v-if="tables.length" class="ml-1.5 text-xs font-normal text-slate-500">
                ({{ tableFilter.trim() ? `${filteredTables.length}/` : "" }}{{ tables.length }})
              </span>
            </div>
            <button
              type="button"
              class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100"
              title="Refresh"
              @click="loadTables"
            >
              <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': loadingTables }" />
            </button>
          </div>
          <div class="border-b border-slate-100 p-2">
            <div class="relative">
              <Search class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input
                v-model="tableFilter"
                type="search"
                placeholder="Search… e.g. users"
                autocomplete="off"
                class="w-full rounded-lg border border-slate-200 py-1.5 pl-8 pr-2 text-sm outline-none focus:border-slate-400"
              />
            </div>
            <p class="mt-1.5 px-0.5 text-[11px] leading-snug text-slate-500">
              Sorted A–Z — <strong class="font-medium text-slate-600">scroll inside the table list</strong> below when there are many. Or type
              <kbd class="rounded border border-slate-200 bg-white px-1">users</kbd> to jump.
            </p>
          </div>
          <div
            class="h-[min(52dvh,380px)] overflow-y-auto overscroll-y-contain p-1 pb-2 sm:h-[min(58dvh,440px)] lg:h-[min(62dvh,520px)] [scrollbar-gutter:stable]"
            role="listbox"
            aria-label="Database tables"
          >
            <div v-if="loadingTables && !tables.length" class="px-3 py-6 text-center text-sm text-slate-400">Loading…</div>
            <button
              v-for="name in filteredTables"
              :key="name"
              type="button"
              class="mb-0.5 w-full rounded-md px-3 py-1.5 text-left text-sm transition-colors"
              :class="
                selectedTable === name ? 'bg-slate-900 font-medium text-white' : 'text-slate-700 hover:bg-slate-100'
              "
              @click="selectTable(name)"
            >
              {{ name }}
            </button>
            <p v-if="!loadingTables && filteredTables.length === 0" class="px-3 py-4 text-center text-sm text-slate-400">
              No tables
            </p>
          </div>
        </aside>

        <section class="min-w-0 flex-1 space-y-3">
          <div v-if="!selectedTable" class="rounded-lg border border-dashed border-slate-200 bg-slate-50/80 px-6 py-16 text-center text-sm text-slate-500">
            Select a table to load schema and rows.
          </div>

          <template v-else>
            <div class="flex flex-wrap items-center justify-between gap-2">
              <h2 class="text-lg font-semibold text-slate-900">{{ selectedTable }}</h2>
              <div class="flex flex-wrap items-center gap-2">
                <button
                  v-if="isWritable"
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800"
                  @click="openAdd"
                >
                  <Plus class="h-4 w-4" />
                  Add row
                </button>
                <button
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                  @click="loadTableData"
                >
                  <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': loadingData }" />
                  Refresh
                </button>
              </div>
            </div>

            <article v-if="columns.length" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
              <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Columns</h3>
              <ul class="flex flex-wrap gap-2 text-xs">
                <li
                  v-for="c in columns"
                  :key="c.name"
                  class="rounded-md border border-slate-100 bg-slate-50 px-2 py-1 font-mono text-slate-700"
                >
                  {{ c.name }}
                  <span class="text-slate-400">· {{ c.type }}</span>
                  <span v-if="c.primaryKey" class="text-blue-600">· PK</span>
                  <span v-if="c.nullable" class="text-slate-400">· null</span>
                </li>
              </ul>
            </article>

            <article class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
              <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-2">
                <span class="text-sm font-medium text-slate-700">Rows</span>
                <div v-if="meta" class="text-xs text-slate-500">
                  Page {{ meta.page }} / {{ meta.totalPages || 1 }} · {{ meta.total }} total
                </div>
              </div>
              <div v-if="loadingData" class="px-4 py-10 text-center text-sm text-slate-400">Loading…</div>
              <div v-else class="overflow-x-auto">
                <table v-if="displayColumnNames.length" class="w-full min-w-[640px] text-sm">
                  <thead>
                    <tr class="border-b border-slate-100 text-left">
                      <th
                        v-for="col in displayColumnNames"
                        :key="col"
                        class="cursor-pointer select-none px-3 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500 hover:bg-slate-50"
                        @click="toggleSort(col)"
                      >
                        {{ col }}
                        <span v-if="sortBy === col" class="text-slate-800">{{ sortDir === "asc" ? "↑" : "↓" }}</span>
                      </th>
                      <th v-if="canMutateRows" class="px-3 py-2 text-right text-xs font-semibold uppercase text-slate-500">
                        Actions
                      </th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <tr v-for="(row, idx) in rows" :key="idx" class="hover:bg-slate-50/80">
                      <td v-for="col in displayColumnNames" :key="col" class="max-w-[240px] truncate px-3 py-2 font-mono text-xs text-slate-800">
                        {{ displayCell(rowValue(row, col)) }}
                      </td>
                      <td v-if="canMutateRows" class="whitespace-nowrap px-3 py-2 text-right">
                        <button
                          type="button"
                          class="mr-1 inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                          title="Edit"
                          @click="openEdit(row)"
                        >
                          <Pencil class="h-4 w-4" />
                        </button>
                        <button
                          type="button"
                          class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-red-600"
                          title="Delete"
                          @click="removeRow(row)"
                        >
                          <Trash2 class="h-4 w-4" />
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
                <p v-else class="px-4 py-8 text-center text-sm text-slate-400">No columns</p>
              </div>
              <div v-if="meta && meta.totalPages > 1" class="flex items-center justify-end gap-2 border-t border-slate-100 px-4 py-2">
                <button
                  type="button"
                  class="rounded-lg border border-slate-200 px-3 py-1 text-sm disabled:opacity-40"
                  :disabled="page <= 1"
                  @click="goPrevPage"
                >
                  Prev
                </button>
                <button
                  type="button"
                  class="rounded-lg border border-slate-200 px-3 py-1 text-sm disabled:opacity-40"
                  :disabled="page >= meta.totalPages"
                  @click="goNextPage"
                >
                  Next
                </button>
              </div>
            </article>
          </template>
        </section>
      </div>
    </div>

    <!-- Add row -->
    <Teleport to="body">
      <div
        v-if="addOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        role="dialog"
        aria-modal="true"
        @click.self="addOpen = false"
      >
        <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-5 shadow-xl">
          <h3 class="mb-4 text-lg font-semibold">Add row — {{ selectedTable }}</h3>
          <div class="space-y-3">
            <label v-for="c in columns" :key="c.name" class="block text-sm">
              <span class="mb-1 block font-medium text-slate-700">
                {{ c.name }}
                <span class="font-normal text-slate-400">({{ c.type }})</span>
                <span v-if="c.primaryKey" class="text-blue-600">PK</span>
              </span>
              <textarea
                v-model="formValues[c.name]"
                rows="2"
                class="w-full rounded-lg border border-slate-200 px-2 py-1.5 font-mono text-xs"
                :placeholder="c.nullable ? 'empty → NULL' : ''"
              />
            </label>
          </div>
          <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100" @click="addOpen = false">
              Cancel
            </button>
            <button
              type="button"
              class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
              @click="saveAdd"
            >
              Insert
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Edit row -->
    <Teleport to="body">
      <div
        v-if="editOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        role="dialog"
        aria-modal="true"
        @click.self="editOpen = false"
      >
        <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-5 shadow-xl">
          <h3 class="mb-4 text-lg font-semibold">Edit row — {{ selectedTable }}</h3>
          <div class="space-y-3">
            <label v-for="c in columns" :key="c.name" class="block text-sm">
              <span class="mb-1 block font-medium text-slate-700">
                {{ c.name }}
                <span v-if="c.primaryKey" class="text-blue-600">(PK — not sent on update)</span>
              </span>
              <textarea
                v-if="!c.primaryKey"
                v-model="formValues[c.name]"
                rows="2"
                class="w-full rounded-lg border border-slate-200 px-2 py-1.5 font-mono text-xs"
              />
              <div v-else class="rounded bg-slate-50 px-2 py-1.5 font-mono text-xs text-slate-600">
                {{ displayCell(editingRow ? rowValue(editingRow, c.name) : undefined) }}
              </div>
            </label>
          </div>
          <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100" @click="editOpen = false">
              Cancel
            </button>
            <button
              type="button"
              class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
              @click="saveEdit"
            >
              Save
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </AdminLayout>
</template>
