<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { Database, RefreshCw, TableProperties, Plus, Pencil, Trash2 } from "lucide-vue-next";

import AdminLayout from "@/layouts/AdminLayout.vue";
import {
  createDbRow,
  createDbTable,
  deleteDbRow,
  deleteDbTable,
  getDbTableRows,
  getDbTableSchema,
  listDbTables,
  updateDbRow,
} from "@/api/cms";
import { useConfirmDialog } from "@/composables/useConfirmDialog";
import { useToast } from "@/composables/useToast";
import type { DbEditorColumnType, DbEditorRowsPayload, DbEditorSchema } from "@/types";

type DraftColumn = { name: string; type: DbEditorColumnType; nullable: boolean };

const confirmDialog = useConfirmDialog();
const toast = useToast();

const loading = ref(false);
const tables = ref<string[]>([]);
const driver = ref("");
const database = ref("");
const selectedTable = ref("");

const schema = ref<DbEditorSchema | null>(null);
const rowsPayload = ref<DbEditorRowsPayload | null>(null);
const page = ref(1);
const limit = ref(25);

const showCreateTable = ref(false);
const newTableName = ref("");
const newPrimaryKey = ref("");
const newWithTimestamps = ref(true);
const draftColumns = ref<DraftColumn[]>([
  { name: "id", type: "bigInteger", nullable: false },
]);

const createRowForm = ref<Record<string, unknown>>({});
const editRowId = ref<string | null>(null);
const editRowForm = ref<Record<string, unknown>>({});

const editableColumns = computed(() =>
  (schema.value?.columns ?? []).filter((col) => !(col.isPrimary && (col.extra ?? "").includes("auto_increment"))),
);

async function loadTables() {
  loading.value = true;
  try {
    const res = await listDbTables();
    tables.value = res.data.tables;
    driver.value = res.data.driver;
    database.value = res.data.database;

    if (!selectedTable.value && tables.value.length > 0) {
      selectedTable.value = tables.value[0];
    } else if (selectedTable.value && !tables.value.includes(selectedTable.value)) {
      selectedTable.value = tables.value[0] ?? "";
    }
  } catch (e) {
    toast.error("Failed to load tables", e instanceof Error ? e.message : "Unable to read database tables.");
  } finally {
    loading.value = false;
  }
}

async function loadTableData() {
  if (!selectedTable.value) {
    schema.value = null;
    rowsPayload.value = null;
    return;
  }

  loading.value = true;
  try {
    const [schemaRes, rowsRes] = await Promise.all([
      getDbTableSchema(selectedTable.value),
      getDbTableRows(selectedTable.value, page.value, limit.value),
    ]);
    schema.value = schemaRes.data;
    rowsPayload.value = rowsRes.data;
    initializeCreateRowForm();
  } catch (e) {
    toast.error("Failed to load table data", e instanceof Error ? e.message : "Unable to load schema and rows.");
  } finally {
    loading.value = false;
  }
}

function initializeCreateRowForm() {
  const next: Record<string, unknown> = {};
  for (const col of editableColumns.value) {
    next[col.name] = "";
  }
  createRowForm.value = next;
}

function addDraftColumn() {
  draftColumns.value.push({
    name: "",
    type: "string",
    nullable: true,
  });
}

function removeDraftColumn(idx: number) {
  if (draftColumns.value.length <= 1) return;
  draftColumns.value.splice(idx, 1);
}

async function submitCreateTable() {
  if (!newTableName.value.trim()) {
    toast.error("Table name is required.");
    return;
  }

  const tableName = newTableName.value.trim();
  try {
    await createDbTable({
      table: tableName,
      columns: draftColumns.value.map((col) => ({
        name: col.name.trim(),
        type: col.type,
        nullable: col.nullable,
      })),
      primaryKey: newPrimaryKey.value.trim() || undefined,
      withTimestamps: newWithTimestamps.value,
    });

    toast.success("Table created");
    showCreateTable.value = false;
    newTableName.value = "";
    newPrimaryKey.value = "";
    newWithTimestamps.value = true;
    draftColumns.value = [{ name: "id", type: "bigInteger", nullable: false }];
    await loadTables();
    selectedTable.value = tableName;
    await loadTableData();
  } catch (e) {
    toast.error("Create table failed", e instanceof Error ? e.message : "Unable to create table.");
  }
}

async function submitCreateRow() {
  if (!selectedTable.value) return;
  try {
    await createDbRow(selectedTable.value, createRowForm.value);
    toast.success("Row inserted");
    await loadTableData();
  } catch (e) {
    toast.error("Insert row failed", e instanceof Error ? e.message : "Unable to insert row.");
  }
}

function openEditRow(row: Record<string, unknown>) {
  if (!rowsPayload.value?.primaryKey) return;
  const rowId = row[rowsPayload.value.primaryKey];
  if (rowId === undefined || rowId === null) return;

  editRowId.value = String(rowId);
  const payload: Record<string, unknown> = {};
  for (const col of editableColumns.value) {
    payload[col.name] = row[col.name] ?? "";
  }
  editRowForm.value = payload;
}

async function submitEditRow() {
  if (!selectedTable.value || !editRowId.value) return;
  try {
    await updateDbRow(selectedTable.value, editRowId.value, editRowForm.value);
    toast.success("Row updated");
    editRowId.value = null;
    await loadTableData();
  } catch (e) {
    toast.error("Update row failed", e instanceof Error ? e.message : "Unable to update row.");
  }
}

async function confirmDeleteRow(row: Record<string, unknown>) {
  if (!selectedTable.value || !rowsPayload.value?.primaryKey) return;
  const rowId = row[rowsPayload.value.primaryKey];
  if (rowId === undefined || rowId === null) return;

  const allowed = await confirmDialog.confirm({
    title: "Delete row?",
    message: "This action cannot be undone.",
    confirmText: "Delete",
    destructive: true,
  });
  if (!allowed) return;

  try {
    await deleteDbRow(selectedTable.value, String(rowId));
    toast.success("Row deleted");
    await loadTableData();
  } catch (e) {
    toast.error("Delete row failed", e instanceof Error ? e.message : "Unable to delete row.");
  }
}

async function confirmDropTable() {
  if (!selectedTable.value) return;
  const allowed = await confirmDialog.confirm({
    title: `Drop table ${selectedTable.value}?`,
    message: "This removes table structure and all rows permanently.",
    confirmText: "Drop table",
    destructive: true,
  });
  if (!allowed) return;

  try {
    await deleteDbTable(selectedTable.value);
    toast.success("Table dropped");
    selectedTable.value = "";
    await loadTables();
    await loadTableData();
  } catch (e) {
    toast.error("Drop table failed", e instanceof Error ? e.message : "Unable to drop table.");
  }
}

watch(selectedTable, async () => {
  page.value = 1;
  await loadTableData();
});

watch(page, async () => {
  await loadTableData();
});

onMounted(async () => {
  await loadTables();
  await loadTableData();
});
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-7xl space-y-4">
      <div class="flex items-center justify-between gap-3">
        <h1 class="page-title">Database Editor</h1>
        <div class="flex items-center gap-2">
          <button
            class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
            @click="loadTables"
          >
            <RefreshCw class="h-4 w-4" />
            Refresh
          </button>
          <button
            class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800"
            @click="showCreateTable = true"
          >
            <Plus class="h-4 w-4" />
            New Table
          </button>
        </div>
      </div>

      <article class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
          <div class="flex items-center gap-2">
            <Database class="h-4 w-4 text-emerald-600" />
            <h2 class="text-sm font-semibold text-slate-900">Connection</h2>
          </div>
          <p class="text-xs text-slate-500">{{ driver || "-" }} / {{ database || "-" }}</p>
        </div>
        <div class="grid gap-3 p-4 md:grid-cols-[280px_1fr]">
          <div>
            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">Table</label>
            <select v-model="selectedTable" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option disabled value="">Select table</option>
              <option v-for="t in tables" :key="t" :value="t">{{ t }}</option>
            </select>
            <button
              v-if="selectedTable"
              class="mt-2 inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 hover:bg-rose-100"
              @click="confirmDropTable"
            >
              <Trash2 class="h-4 w-4" />
              Drop Table
            </button>
          </div>

          <div class="rounded-lg border border-slate-100 bg-slate-50 p-3 text-sm text-slate-600">
            <p v-if="schema">Rows: <strong>{{ schema.rowCount }}</strong></p>
            <p v-if="schema">Primary Key: <strong>{{ schema.primaryKey || "-" }}</strong></p>
            <p v-if="loading" class="text-slate-500">Loading...</p>
          </div>
        </div>
      </article>

      <article v-if="schema" class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5">
          <TableProperties class="h-4 w-4 text-blue-600" />
          <h2 class="text-sm font-semibold text-slate-900">Columns - {{ schema.table }}</h2>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50">
              <tr class="border-b border-slate-100 text-left">
                <th class="px-3 py-2 text-xs uppercase tracking-wider text-slate-500">Column</th>
                <th class="px-3 py-2 text-xs uppercase tracking-wider text-slate-500">Type</th>
                <th class="px-3 py-2 text-xs uppercase tracking-wider text-slate-500">Nullable</th>
                <th class="px-3 py-2 text-xs uppercase tracking-wider text-slate-500">Default</th>
                <th class="px-3 py-2 text-xs uppercase tracking-wider text-slate-500">Primary</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="col in schema.columns" :key="col.name">
                <td class="px-3 py-2 font-medium text-slate-900">{{ col.name }}</td>
                <td class="px-3 py-2 text-slate-600">{{ col.type }}</td>
                <td class="px-3 py-2 text-slate-600">{{ col.nullable ? "YES" : "NO" }}</td>
                <td class="px-3 py-2 text-slate-600">{{ col.default ?? "-" }}</td>
                <td class="px-3 py-2 text-slate-600">{{ col.isPrimary ? "YES" : "-" }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>

      <article v-if="rowsPayload && schema" class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
          <h2 class="text-sm font-semibold text-slate-900">Rows</h2>
          <div class="flex items-center gap-2 text-xs text-slate-500">
            <button
              class="rounded border border-slate-300 px-2 py-1 disabled:opacity-50"
              :disabled="page <= 1"
              @click="page = page - 1"
            >
              Prev
            </button>
            <span>Page {{ page }}</span>
            <button
              class="rounded border border-slate-300 px-2 py-1 disabled:opacity-50"
              :disabled="page * limit >= rowsPayload.total"
              @click="page = page + 1"
            >
              Next
            </button>
          </div>
        </div>

        <div class="p-4">
          <h3 class="mb-2 text-sm font-semibold text-slate-900">Insert Row</h3>
          <div class="grid gap-2 md:grid-cols-3">
            <div v-for="col in editableColumns" :key="`create-${col.name}`">
              <label class="mb-1 block text-xs text-slate-500">{{ col.name }}</label>
              <input
                v-model="createRowForm[col.name]"
                type="text"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                :placeholder="col.type"
              />
            </div>
          </div>
          <button
            class="mt-3 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700"
            @click="submitCreateRow"
          >
            Insert
          </button>
        </div>

        <div class="overflow-x-auto border-t border-slate-100">
          <table class="w-full text-sm">
            <thead class="bg-slate-50">
              <tr class="border-b border-slate-100 text-left">
                <th v-for="col in schema.columns" :key="`h-${col.name}`" class="px-3 py-2 text-xs uppercase tracking-wider text-slate-500">
                  {{ col.name }}
                </th>
                <th class="px-3 py-2 text-right text-xs uppercase tracking-wider text-slate-500">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="(row, idx) in rowsPayload.rows" :key="idx">
                <td v-for="col in schema.columns" :key="`${idx}-${col.name}`" class="max-w-[260px] truncate px-3 py-2 text-slate-700">
                  {{ row[col.name] }}
                </td>
                <td class="px-3 py-2 text-right">
                  <div class="inline-flex items-center gap-1">
                    <button class="rounded p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-900" @click="openEditRow(row)">
                      <Pencil class="h-4 w-4" />
                    </button>
                    <button class="rounded p-1 text-rose-500 hover:bg-rose-50 hover:text-rose-700" @click="confirmDeleteRow(row)">
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="rowsPayload.rows.length === 0">
                <td :colspan="schema.columns.length + 1" class="px-3 py-6 text-center text-sm text-slate-400">
                  No data found.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>
    </div>

    <div v-if="showCreateTable" class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4">
      <div class="w-full max-w-2xl rounded-xl bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
          <h3 class="text-sm font-semibold text-slate-900">Create Table</h3>
          <button class="text-sm text-slate-500 hover:text-slate-800" @click="showCreateTable = false">Close</button>
        </div>
        <div class="space-y-3 p-4">
          <div>
            <label class="mb-1 block text-xs text-slate-500">Table Name</label>
            <input v-model="newTableName" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <div class="grid gap-2">
            <div v-for="(col, idx) in draftColumns" :key="`draft-${idx}`" class="grid grid-cols-[1fr_180px_100px_40px] gap-2">
              <input v-model="col.name" placeholder="column_name" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
              <select v-model="col.type" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="string">string</option>
                <option value="text">text</option>
                <option value="longText">longText</option>
                <option value="integer">integer</option>
                <option value="bigInteger">bigInteger</option>
                <option value="boolean">boolean</option>
                <option value="dateTime">dateTime</option>
                <option value="json">json</option>
              </select>
              <label class="flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">
                <input v-model="col.nullable" type="checkbox" />
                null
              </label>
              <button class="rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50" @click="removeDraftColumn(idx)">x</button>
            </div>
            <button class="w-fit rounded-lg border border-slate-300 px-3 py-1.5 text-sm" @click="addDraftColumn">Add column</button>
          </div>
          <div class="grid gap-3 md:grid-cols-2">
            <div>
              <label class="mb-1 block text-xs text-slate-500">Primary Key (optional)</label>
              <input v-model="newPrimaryKey" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="id" />
            </div>
            <label class="mt-6 inline-flex items-center gap-2 text-sm text-slate-700">
              <input v-model="newWithTimestamps" type="checkbox" />
              add created_at / updated_at
            </label>
          </div>
        </div>
        <div class="flex justify-end gap-2 border-t border-slate-100 px-4 py-3">
          <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm" @click="showCreateTable = false">Cancel</button>
          <button class="rounded-lg bg-slate-900 px-3 py-1.5 text-sm font-medium text-white" @click="submitCreateTable">Create</button>
        </div>
      </div>
    </div>

    <div v-if="editRowId" class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4">
      <div class="w-full max-w-2xl rounded-xl bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
          <h3 class="text-sm font-semibold text-slate-900">Edit Row #{{ editRowId }}</h3>
          <button class="text-sm text-slate-500 hover:text-slate-800" @click="editRowId = null">Close</button>
        </div>
        <div class="grid max-h-[65vh] gap-3 overflow-y-auto p-4 md:grid-cols-2">
          <div v-for="col in editableColumns" :key="`edit-${col.name}`">
            <label class="mb-1 block text-xs text-slate-500">{{ col.name }}</label>
            <input
              v-model="editRowForm[col.name]"
              type="text"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              :placeholder="col.type"
            />
          </div>
        </div>
        <div class="flex justify-end gap-2 border-t border-slate-100 px-4 py-3">
          <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm" @click="editRowId = null">Cancel</button>
          <button class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white" @click="submitEditRow">Save</button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
