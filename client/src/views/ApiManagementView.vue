<script setup lang="ts">
import { computed, ref } from "vue";
import { Braces, Check, ChevronDown, Copy, Search, ShieldCheck } from "lucide-vue-next";

import { API_BASE_URL } from "@/env";
import AdminLayout from "@/layouts/AdminLayout.vue";

type HttpMethod = "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
type MethodTab = "ALL" | HttpMethod;
type EndpointAuth = "public" | "auth";

type Endpoint = {
  method: HttpMethod;
  path: string;
  summary: string;
  auth: EndpointAuth;
  csrf: boolean;
  body?: string;
  contentType?: "application/json" | "multipart/form-data";
};

type Group = { label: string; endpoints: Endpoint[] };

const groups: Group[] = [
  {
    label: "Auth",
    endpoints: [
      { method: "POST", path: "/api/auth/login", summary: "Login and create session", auth: "public", csrf: false, body: '{ "email": "admin@example.com", "password": "admin12345" }' },
      { method: "POST", path: "/api/auth/logout", summary: "Logout and clear session", auth: "auth", csrf: true },
      { method: "GET", path: "/api/auth/me", summary: "Get current authenticated user", auth: "auth", csrf: false },
      { method: "PUT", path: "/api/auth/me", summary: "Update current user profile", auth: "auth", csrf: true, body: '{ "name": "Admin User", "email": "admin@example.com" }' },
      { method: "POST", path: "/api/auth/password", summary: "Change current user password", auth: "auth", csrf: true, body: '{ "current_password": "old12345", "new_password": "new12345" }' },
      { method: "POST", path: "/api/auth/avatar", summary: "Upload profile avatar", auth: "auth", csrf: true, contentType: "multipart/form-data" },
      { method: "DELETE", path: "/api/auth/avatar", summary: "Delete profile avatar", auth: "auth", csrf: true },
    ],
  },
  {
    label: "Content",
    endpoints: [
      { method: "GET", path: "/api/posts", summary: "List posts", auth: "auth", csrf: false },
      { method: "POST", path: "/api/posts", summary: "Create post", auth: "auth", csrf: true, body: '{ "title": "New post", "content": "Post body", "status": "draft" }' },
      { method: "GET", path: "/api/posts/{post}", summary: "Get post by id", auth: "auth", csrf: false },
      { method: "PUT", path: "/api/posts/{post}", summary: "Update post", auth: "auth", csrf: true },
      { method: "DELETE", path: "/api/posts/{post}", summary: "Delete post", auth: "auth", csrf: true },
      { method: "GET", path: "/api/pages", summary: "List pages", auth: "auth", csrf: false },
      { method: "POST", path: "/api/pages", summary: "Create page", auth: "auth", csrf: true, body: '{ "title": "About", "content": "Page body", "status": "draft" }' },
      { method: "GET", path: "/api/pages/{page}", summary: "Get page by id", auth: "auth", csrf: false },
      { method: "PUT", path: "/api/pages/{page}", summary: "Update page", auth: "auth", csrf: true },
      { method: "DELETE", path: "/api/pages/{page}", summary: "Delete page", auth: "auth", csrf: true },
      { method: "GET", path: "/api/categories", summary: "List categories", auth: "auth", csrf: false },
      { method: "POST", path: "/api/categories", summary: "Create category", auth: "auth", csrf: true, body: '{ "name": "News", "description": "News items" }' },
      { method: "GET", path: "/api/categories/{category}", summary: "Get category by id", auth: "auth", csrf: false },
      { method: "PUT", path: "/api/categories/{category}", summary: "Update category", auth: "auth", csrf: true },
      { method: "DELETE", path: "/api/categories/{category}", summary: "Delete category", auth: "auth", csrf: true },
    ],
  },
  {
    label: "Media",
    endpoints: [
      { method: "GET", path: "/api/media", summary: "List uploaded media", auth: "auth", csrf: false },
      { method: "POST", path: "/api/media/upload", summary: "Upload media file", auth: "auth", csrf: true, contentType: "multipart/form-data" },
      { method: "PUT", path: "/api/media/{media}", summary: "Update media metadata", auth: "auth", csrf: true, body: '{ "title": "Hero image", "alt_text": "Team at work" }' },
      { method: "DELETE", path: "/api/media/{media}", summary: "Delete media", auth: "auth", csrf: true },
    ],
  },
  {
    label: "Settings",
    endpoints: [
      { method: "GET", path: "/api/settings", summary: "Get site settings", auth: "public", csrf: false },
      { method: "PUT", path: "/api/settings", summary: "Update site settings", auth: "auth", csrf: true },
      { method: "GET", path: "/api/settings/admin-menu-prefs", summary: "Get admin menu preferences", auth: "auth", csrf: false },
      { method: "PUT", path: "/api/settings/admin-menu-prefs", summary: "Save admin menu preferences", auth: "auth", csrf: true },
      { method: "GET", path: "/api/settings/storefront-menu", summary: "Get storefront menu", auth: "auth", csrf: false },
      { method: "PUT", path: "/api/settings/storefront-menu", summary: "Save storefront menu", auth: "auth", csrf: true },
    ],
  },
  {
    label: "Public",
    endpoints: [
      { method: "GET", path: "/api/public/site", summary: "Public site metadata", auth: "public", csrf: false },
      { method: "GET", path: "/api/public/pages/frontpage", summary: "Resolve published front page", auth: "public", csrf: false },
      { method: "GET", path: "/api/public/pages/{slug}", summary: "Get published page by slug", auth: "public", csrf: false },
    ],
  },
  {
    label: "Administration",
    endpoints: [
      { method: "GET", path: "/api/users", summary: "List users", auth: "auth", csrf: false },
      { method: "POST", path: "/api/users", summary: "Create user", auth: "auth", csrf: true, body: '{ "name": "Editor", "email": "editor@example.com", "password": "secret123", "role": "editor", "is_active": true }' },
      { method: "GET", path: "/api/users/{user}", summary: "Get user by id", auth: "auth", csrf: false },
      { method: "PUT", path: "/api/users/{user}", summary: "Update user", auth: "auth", csrf: true },
      { method: "DELETE", path: "/api/users/{user}", summary: "Delete user", auth: "auth", csrf: true },
      { method: "GET", path: "/api/roles", summary: "List roles", auth: "auth", csrf: false },
      { method: "POST", path: "/api/roles", summary: "Create role", auth: "auth", csrf: true, body: '{ "name": "editor", "description": "Can edit content", "permissions": ["posts.read", "posts.write"] }' },
      { method: "GET", path: "/api/roles/{role}", summary: "Get role by id", auth: "auth", csrf: false },
      { method: "PATCH", path: "/api/roles/{role}", summary: "Update role", auth: "auth", csrf: true },
      { method: "DELETE", path: "/api/roles/{role}", summary: "Delete role", auth: "auth", csrf: true },
      { method: "GET", path: "/api/dashboard/summary", summary: "Dashboard summary stats", auth: "auth", csrf: false },
      { method: "GET", path: "/api/audit-logs", summary: "List audit logs with filters and pagination", auth: "auth", csrf: false },
    ],
  },
];

const query = ref("");
const activeTab = ref<MethodTab>("ALL");
const requiresCsrfOnly = ref(false);
const copiedEndpointKey = ref("");

const allEndpoints = computed(() => groups.flatMap((group) => group.endpoints));
const tabs: MethodTab[] = ["ALL", "GET", "POST", "PUT", "PATCH", "DELETE"];

const tabStats = computed(() => ({
  ALL: allEndpoints.value.length,
  GET: allEndpoints.value.filter((endpoint) => endpoint.method === "GET").length,
  POST: allEndpoints.value.filter((endpoint) => endpoint.method === "POST").length,
  PUT: allEndpoints.value.filter((endpoint) => endpoint.method === "PUT").length,
  PATCH: allEndpoints.value.filter((endpoint) => endpoint.method === "PATCH").length,
  DELETE: allEndpoints.value.filter((endpoint) => endpoint.method === "DELETE").length,
}));

const filteredGroups = computed(() => {
  const q = query.value.trim().toLowerCase();

  return groups
    .map((group) => ({
      ...group,
      endpoints: group.endpoints.filter((endpoint) => {
        if (activeTab.value !== "ALL" && endpoint.method !== activeTab.value) return false;
        if (requiresCsrfOnly.value && !endpoint.csrf) return false;
        if (!q) return true;
        const haystack = `${endpoint.method} ${endpoint.path} ${endpoint.summary} ${endpoint.auth}`.toLowerCase();
        return haystack.includes(q);
      }),
    }))
    .filter((group) => group.endpoints.length > 0);
});

function methodClass(method: HttpMethod) {
  if (method === "GET") return "bg-emerald-100 text-emerald-700";
  if (method === "POST") return "bg-blue-100 text-blue-700";
  if (method === "PUT") return "bg-amber-100 text-amber-700";
  if (method === "PATCH") return "bg-violet-100 text-violet-700";
  return "bg-rose-100 text-rose-700";
}

function authLabel(endpoint: Endpoint) {
  if (endpoint.auth === "public") return endpoint.csrf ? "Public + CSRF" : "Public";
  return endpoint.csrf ? "Auth + CSRF" : "Auth";
}

function endpointKey(endpoint: Endpoint) {
  return `${endpoint.method}:${endpoint.path}`;
}

function toExamplePath(path: string) {
  return path
    .replaceAll(":slug", "home")
    .replace(/:[a-zA-Z_][a-zA-Z0-9_]*/g, "1")
    .replaceAll("{slug}", "home")
    .replace(/\{[a-zA-Z_][a-zA-Z0-9_]*\}/g, "1");
}

function buildCurlCommand(endpoint: Endpoint) {
  const fullPath = `${API_BASE_URL}${toExamplePath(endpoint.path)}`;
  const parts = [`curl -X ${endpoint.method}`, `"${fullPath}"`];

  if (endpoint.contentType === "application/json" || endpoint.body) {
    parts.push('-H "Content-Type: application/json"');
  }
  if (endpoint.csrf) {
    parts.push('-H "x-csrf-token: <csrf-token>"');
  }
  if (endpoint.body) {
    parts.push(`-d '${endpoint.body}'`);
  }
  if (endpoint.contentType === "multipart/form-data") {
    parts.push('-F "file=@/path/to/file.png"');
  }

  return parts.join(" ");
}

async function copyCurl(endpoint: Endpoint) {
  const key = endpointKey(endpoint);
  const text = buildCurlCommand(endpoint);
  try {
    await navigator.clipboard.writeText(text);
    copiedEndpointKey.value = key;
    setTimeout(() => {
      if (copiedEndpointKey.value === key) copiedEndpointKey.value = "";
    }, 1500);
  } catch {
    copiedEndpointKey.value = "";
  }
}
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-7xl space-y-4">
      <div class="flex items-center justify-between">
        <h1 class="page-title">API Management</h1>
      </div>

      <article class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="space-y-3 border-b border-slate-100 px-4 py-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
              <Braces class="h-4 w-4 text-blue-600" />
              <h2 class="text-sm font-semibold text-slate-900">Endpoint Explorer</h2>
            </div>
            <span class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-600">
              Base URL: <code class="font-medium text-slate-800">{{ API_BASE_URL }}</code>
            </span>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <button
              v-for="tab in tabs"
              :key="tab"
              class="inline-flex items-center gap-2 rounded-md border px-2.5 py-1.5 text-xs font-medium transition-colors"
              :class="activeTab === tab ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 text-slate-700 hover:bg-slate-50'"
              @click="activeTab = tab"
            >
              <span>{{ tab }}</span>
              <span
                class="rounded-full px-1.5 py-0.5 text-[11px]"
                :class="activeTab === tab ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'"
              >
                {{ tabStats[tab] }}
              </span>
            </button>

            <label class="ml-auto inline-flex cursor-pointer items-center gap-2 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs text-slate-700 hover:bg-slate-50">
              <input v-model="requiresCsrfOnly" type="checkbox" class="h-3.5 w-3.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500" />
              CSRF required only
            </label>
          </div>

          <div class="relative w-full max-w-sm">
            <Search class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
            <input
              v-model="query"
              class="w-full rounded-md border border-slate-300 py-1.5 pl-8 pr-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
              placeholder="Search endpoint"
            />
          </div>
        </div>

        <div class="space-y-3 p-4">
          <details
            v-for="(group, index) in filteredGroups"
            :key="group.label"
            class="group overflow-hidden rounded-lg border border-slate-200"
            :open="index === 0"
          >
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-100">
              <div class="flex items-center gap-2">
                <span>{{ group.label }}</span>
                <span class="rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-medium text-slate-700">{{ group.endpoints.length }}</span>
              </div>
              <ChevronDown class="h-4 w-4 text-slate-500 transition-transform group-open:rotate-180" />
            </summary>

            <div class="divide-y divide-slate-100">
              <details v-for="endpoint in group.endpoints" :key="endpointKey(endpoint)" class="group">
                <summary class="flex cursor-pointer items-center justify-between gap-3 px-3 py-2.5 text-sm hover:bg-slate-50">
                  <div class="min-w-0 flex items-center gap-2">
                    <span class="rounded px-2 py-0.5 text-[11px] font-semibold" :class="methodClass(endpoint.method)">{{ endpoint.method }}</span>
                    <code class="truncate text-xs text-slate-700">{{ endpoint.path }}</code>
                    <span class="truncate text-slate-500">{{ endpoint.summary }}</span>
                  </div>
                  <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">
                    <ShieldCheck class="h-3 w-3" />
                    {{ authLabel(endpoint) }}
                  </span>
                </summary>

                <div class="space-y-2 border-t border-slate-100 bg-slate-50/50 px-3 py-2.5 text-xs text-slate-600">
                  <p><span class="font-semibold text-slate-800">Auth:</span> {{ authLabel(endpoint) }}</p>
                  <p><span class="font-semibold text-slate-800">Request:</span> <code>{{ endpoint.method }} {{ toExamplePath(endpoint.path) }}</code></p>
                  <p v-if="endpoint.contentType"><span class="font-semibold text-slate-800">Content-Type:</span> {{ endpoint.contentType }}</p>
                  <div class="flex items-center gap-2 pt-1">
                    <button
                      class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-2 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-100"
                      @click="copyCurl(endpoint)"
                    >
                      <Check v-if="copiedEndpointKey === endpointKey(endpoint)" class="h-3 w-3 text-emerald-600" />
                      <Copy v-else class="h-3 w-3" />
                      {{ copiedEndpointKey === endpointKey(endpoint) ? "Copied" : "Copy cURL" }}
                    </button>
                  </div>
                  <div v-if="endpoint.body" class="space-y-1">
                    <p class="font-semibold text-slate-800">Sample Body</p>
                    <pre class="overflow-x-auto rounded bg-slate-900 p-2 text-[11px] text-slate-100">{{ endpoint.body }}</pre>
                  </div>
                </div>
              </details>
            </div>
          </details>

          <div v-if="filteredGroups.length === 0" class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
            No endpoints match your filter.
          </div>
        </div>
      </article>
    </div>
  </AdminLayout>
</template>
