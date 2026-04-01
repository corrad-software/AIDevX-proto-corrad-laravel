<script setup lang="ts">
import { ref } from "vue";
import { BookOpen, User, Shield, MessageSquare, Info } from "lucide-vue-next";
import AdminLayout from "@/layouts/AdminLayout.vue";
import * as BRANDING from "@/config/branding";

const activeTab = ref<"general" | "user" | "agent" | "admin">("general");
</script>

<template>
  <AdminLayout>
    <div class="mx-auto max-w-4xl px-4 py-6">
      <h1 class="mb-6 flex items-center gap-2 text-xl font-semibold text-gray-900 dark:text-slate-100">
        <BookOpen class="h-6 w-6 text-sky-600 dark:text-sky-400" />
        {{ BRANDING.PLATFORM_HEADER }} — Guide
      </h1>

      <p class="mb-6 text-sm text-gray-600 dark:text-slate-400">
        <strong>{{ BRANDING.PLATFORM_HEADER }}</strong> (<em>{{ BRANDING.PLATFORM_SUBTITLE }}</em>) is the admin suite for
        {{ BRANDING.ERP_SYSTEM_NAME }}. <strong>SELAR</strong> is Support Chat; <strong>AINA</strong> is User Chat.
        This guide starts with <strong>General</strong> (sign-in, notifications, header), then User, Agent, and Admin workflows.
      </p>

      <div class="flex flex-wrap gap-2 mb-6 border-b border-gray-200 dark:border-slate-700">
        <button
          type="button"
          @click="activeTab = 'general'"
          class="px-4 py-2 text-sm font-medium rounded-t-lg transition-colors flex items-center gap-2"
          :class="activeTab === 'general' ? 'bg-slate-100 text-slate-800 border-b-2 border-slate-600 -mb-px dark:bg-slate-800 dark:text-slate-100 dark:border-slate-400' : 'text-gray-600 hover:bg-gray-50 dark:text-slate-400 dark:hover:bg-slate-800/60'"
        >
          <Info class="w-4 h-4" />
          General
        </button>
        <button
          type="button"
          @click="activeTab = 'user'"
          class="px-4 py-2 text-sm font-medium rounded-t-lg transition-colors flex items-center gap-2"
          :class="activeTab === 'user' ? 'bg-emerald-50 text-emerald-700 border-b-2 border-emerald-600 -mb-px' : 'text-gray-600 hover:bg-gray-50'"
        >
          <MessageSquare class="w-4 h-4" />
          User Guide
        </button>
        <button
          type="button"
          @click="activeTab = 'agent'"
          class="px-4 py-2 text-sm font-medium rounded-t-lg transition-colors flex items-center gap-2"
          :class="activeTab === 'agent' ? 'bg-blue-50 text-blue-700 border-b-2 border-blue-600 -mb-px' : 'text-gray-600 hover:bg-gray-50'"
        >
          <User class="w-4 h-4" />
          Agent Guide
        </button>
        <button
          type="button"
          @click="activeTab = 'admin'"
          class="px-4 py-2 text-sm font-medium rounded-t-lg transition-colors flex items-center gap-2"
          :class="activeTab === 'admin' ? 'bg-blue-50 text-blue-700 border-b-2 border-blue-600 -mb-px' : 'text-gray-600 hover:bg-gray-50'"
        >
          <Shield class="w-4 h-4" />
          Admin Guide
        </button>
      </div>

      <!-- General Guide -->
      <article
        v-show="activeTab === 'general'"
        class="prose prose-slate max-w-none rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:prose-invert"
      >
        <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100 mb-4">General Guide</h2>
        <p class="text-sm text-gray-600 dark:text-slate-400 mb-4">
          Everyday use of the portal: how to sign in, stay signed in, read notifications, and use the top bar. Menus and screens you see depend on your <strong>role</strong> and <strong>hierarchy</strong> (see Admin Guide).
        </p>

        <h3 class="text-base font-medium text-gray-800 dark:text-slate-200 mt-6 mb-2">1. Sign in</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700 dark:text-slate-300">
          <li>Open the sign-in page at <strong>/admin/login</strong> (or the URL your organisation gave you).</li>
          <li>Enter your <strong>email</strong> and <strong>password</strong>, then submit. The app uses session-based authentication (cookies) with CSRF protection for API calls.</li>
          <li>If you cannot sign in, check caps lock, try again, or use <strong>Forgot password</strong> at <strong>/admin/forgot-password</strong> if your account supports password reset email.</li>
          <li>New accounts may need <strong>email verification</strong> first; follow the link in the verification email (or the flow your administrator describes).</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 dark:text-slate-200 mt-6 mb-2">2. Session and browser</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700 dark:text-slate-300">
          <li>Do not block cookies for this site; the SPA needs them for login and secure API requests.</li>
          <li>If the page stops loading data or you see repeated auth errors, try signing out and signing in again, or a hard refresh. Your organisation may use a separate Vite dev URL in development — use the URL they standardise on.</li>
          <li><strong>Sign out</strong> from the <strong>Logout</strong> control in the top-right header when you finish on a shared computer.</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 dark:text-slate-200 mt-6 mb-2">3. Top bar — profile, settings, notifications</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700 dark:text-slate-300">
          <li>
            <strong>Profile</strong>: Click your name/avatar area to open your profile route (account details). Update name or email there if your role allows it; password changes use the password section when available.
          </li>
          <li>
            <strong>Theme</strong>: Use the <strong>gear</strong> icon for appearance (light / dark / system) and accent colour, plus compact sidebar if offered.
          </li>
          <li>
            <strong>Notifications</strong>: The <strong>bell</strong> icon opens a short list of <strong>unread</strong> in-app notifications. A dot may appear when there are unread items. Open an item to mark it read (where supported).
          </li>
          <li>
            For the full list, open <strong>{{ BRANDING.PLATFORM_HEADER }} → Notifications</strong> (route <strong>/admin/kerisi/notifications</strong>). There you can review history and manage read state depending on your permissions.
          </li>
          <li>
            Platform admins with the right permission may also use <strong>Administration → Notification center</strong> for broadcast or operational messaging — not all users see this.
          </li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 dark:text-slate-200 mt-6 mb-2">4. Impersonate (staff only)</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700 dark:text-slate-300">
          <li>Some roles (e.g. Super Admin, Internal/External Admin, Agent) may see <strong>Impersonate</strong> in the header to view the app as another user for support.</li>
          <li>Search or pick a user, then confirm. While impersonating, a <strong>Stop impersonating</strong> control returns you to your own account.</li>
          <li>You can only impersonate users allowed by policy; the list respects those rules.</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 dark:text-slate-200 mt-6 mb-2">5. Sidebar and dashboard</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700 dark:text-slate-300">
          <li>The left sidebar lists modules you are allowed to use. Collapse or expand it with the round control on the edge if your layout supports it.</li>
          <li><strong>Dashboard</strong> summarises metrics for your role (e.g. ticket counts, charts). Level 4 users may see personal Desk365 and internal ticket counts plus unread notifications context.</li>
          <li>If a menu item is missing, your role may not have that permission — ask an administrator.</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 dark:text-slate-200 mt-6 mb-2">6. Internal tickets vs Desk365 (orientation)</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700 dark:text-slate-300">
          <li><strong>Desk365</strong> tickets are synced from the external helpdesk; lists and monitoring views show them when configured.</li>
          <li><strong>Internal</strong> tickets are created and handled inside this app (conversations, assignment, AI assistance where enabled).</li>
          <li>Exact menu labels follow your deployment; use the Guide tabs <strong>User</strong>, <strong>Agent</strong>, and <strong>Admin</strong> for deeper steps.</li>
        </ul>
      </article>

      <!-- User Guide -->
      <article
        v-show="activeTab === 'user'"
        class="prose prose-slate max-w-none rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
      >
        <h2 class="text-lg font-semibold text-gray-900 mb-4">User Guide</h2>
        <p class="text-sm text-gray-600 mb-4">
          End users can use <strong>AINA</strong> (User Chat) to ask how-to questions about {{ BRANDING.ERP_SYSTEM_NAME }}. Answers are based on the Knowledge Base (User Manuals, guides). No live data or SQL — for that, contact your support team.
        </p>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">1. User Chat</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li>Click <strong>AINA — User Chat</strong> under the <strong>{{ BRANDING.PLATFORM_HEADER }}</strong> section in the sidebar.</li>
          <li>Type your question about procedures (e.g. how to create a GL journal, how to reconcile cashbook).</li>
          <li>The AI answers from the Knowledge Base — User Manuals, BRS, Walkthrough guides.</li>
          <li>Select a <strong>module</strong> (Cashbook, GL, Payroll, etc.) to focus responses.</li>
          <li>Attach files (PDF, DOCX, Excel, images) — the AI reads the content.</li>
          <li>Use <strong>Favorites</strong> to bookmark useful messages.</li>
          <li>Use <strong>Copy</strong> to copy the chat or a message to clipboard.</li>
          <li>Search within a chat session to find past answers.</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">2. What User Chat Can Do</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li>Explain how to use {{ BRANDING.ERP_SYSTEM_NAME }} (procedures, workflows, screens).</li>
          <li>Answer questions from User Manuals and documentation.</li>
          <li>Provide step-by-step instructions for common tasks.</li>
          <li>Answer in Bahasa Malaysia or English.</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">3. What User Chat Cannot Do</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li>Query live data (balances, counts, transaction lists) — contact support.</li>
          <li>Run SQL or show database schema.</li>
          <li>Resolve tickets or provide technical support — use SELAR Support Chat (agents only) or raise a ticket.</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">4. Ticket</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li>Click <strong>Ticket</strong> to view tickets. As a User, you see only tickets for your customer.</li>
          <li>Search, expand details, copy to clipboard, and use pagination.</li>
          <li>Forward and Open in chat are for Agents only.</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">5. Best Practices</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li>Include the module name when asking (e.g. "In Cashbook, how do I...").</li>
          <li>Be specific — "How to create a voucher?" is better than "voucher".</li>
          <li>For live data requests, contact your administrator or support team.</li>
        </ul>
      </article>

      <!-- Agent Guide -->
      <article
        v-show="activeTab === 'agent'"
        class="prose prose-slate max-w-none rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
      >
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Agent Guide</h2>
        <p class="text-sm text-gray-600 mb-4">
          Agents use <strong>SELAR</strong> to assist customers with questions about {{ BRANDING.ERP_SYSTEM_NAME }} modules (Cashbook, General Ledger, Payroll, etc.).
        </p>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">1. SELAR — Support Chat</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li>Click <strong>SELAR — Support</strong> from the <strong>{{ BRANDING.PLATFORM_HEADER }}</strong> menu.</li>
          <li>Type the customer's question or paste ticket content into the chat.</li>
          <li>The AI answers based on the User Manual and documents in the Knowledge Base.</li>
          <li>Select a <strong>module</strong> (Cashbook, GL, Payroll, etc.) to focus responses.</li>
          <li>Attach files (PDF, DOCX, Excel, images) if needed — the AI reads the content.</li>
          <li>Use <strong>Group Chat</strong> to collaborate with other agents.</li>
          <li>Use <strong>Favorites</strong> to bookmark useful messages.</li>
          <li>Use <strong>Forward</strong> to share a message with another agent.</li>
          <li>Use <strong>Share</strong> to copy a session link for collaboration.</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">2. Ticket</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li>Click <strong>Ticket</strong> to view the list of synced tickets from Desk365.</li>
          <li>Search by ticket number, subject, contact name, company, or assigned agent.</li>
          <li>Click the chevron to expand ticket details.</li>
          <li>Use <strong>Copy</strong> to copy ticket info to the clipboard for pasting into chat.</li>
          <li>Use <strong>Forward</strong> to send the ticket to another agent (creates a group chat with ticket context).</li>
          <li>Click <strong>Open</strong> to bring the ticket into Support Chat — the AI receives the ticket context.</li>
          <li>Tickets are paginated — use the page controls at the bottom to load more.</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">3. {{ BRANDING.ERP_SYSTEM_NAME }} modules</h3>
        <p class="text-sm text-gray-600 mb-2">The system covers these modules:</p>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li>Cashbook, Account Receivable, Account Payable</li>
          <li>General Ledger, Payroll, Purchasing</li>
          <li>Vendor Portal, Debtor Portal, Credit Control</li>
          <li>Investment, Loan, Asset, Budget</li>
          <li>Staff Portal, Setup &amp; Maintenance</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">4. Best Practices</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li>Always include context (module, screen, error message) in questions.</li>
          <li>Use <code class="px-1 py-0.5 bg-gray-100 rounded text-xs">[Ticket NO]</code> format in chat when referring to a ticket.</li>
          <li>Verify AI answers against official references before sending to customers.</li>
          <li>Use module filter when the question is module-specific.</li>
        </ul>
      </article>

      <!-- Admin Guide -->
      <article
        v-show="activeTab === 'admin'"
        class="prose prose-slate max-w-none rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
      >
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Admin Guide</h2>
        <p class="text-sm text-gray-600 mb-4">
          Admins manage the Knowledge Base and AI assistant settings so <strong>SELAR</strong> and <strong>AINA</strong> answer accurately within <strong>{{ BRANDING.PLATFORM_HEADER }}</strong>.
        </p>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">1. Knowledge Base</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li>Click <strong>Knowledge Base</strong> from the <strong>{{ BRANDING.PLATFORM_HEADER }}</strong> menu.</li>
          <li>Upload documents (PDF, DOCX, Excel, TXT) — the AI uses them as references.</li>
          <li>Select a <strong>module</strong> when uploading for better organisation.</li>
          <li>Supported document types: User Manual, Business Logic (BL), Workflow, RBAC, Database Schema, Support Tickets.</li>
          <li>Delete outdated or irrelevant documents.</li>
          <li>Check upload status — <em>uploaded</em> = success, <em>failed</em> = re-upload required.</li>
          <li>Use the search and module filter to find documents quickly.</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">2. Desk365 Logs, Internal Ticket Logs, and AI Sync</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li><strong>Administration → Desk365 log</strong> stores Desk365-to-AI sync history; <strong>Ticket log</strong> stores internal ticket-to-AI sync history.</li>
          <li>Configure Desk365 with <code class="px-1 py-0.5 bg-gray-100 rounded text-xs">DESK365_API_KEY</code> to enable preview and sync for external tickets.</li>
          <li>In <strong>Knowledge Base</strong>, both <strong>Latest Tickets (Desk365)</strong> and <strong>Latest Internal Tickets</strong> include <strong>Sync to AI</strong> actions.</li>
          <li>Internal ticket sync includes conversation context and internal notes (flagged as internal).</li>
          <li>Run sync regularly to keep RAG grounding current.</li>
          <li><strong>Internal Ticket Statistics (AI)</strong> are shown in Knowledge Base and update as synced AI documents become available.</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">3. Ticket monitoring</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li><strong>Administration → Ticket monitoring</strong> (or <strong>{{ BRANDING.PLATFORM_HEADER }} → Ticket monitoring</strong> for non-Super Admin roles) shows status summaries and operational ticket metrics.</li>
          <li>Ensure the role has relevant <em>knowledge</em>/<em>tickets</em> permissions and the appropriate RBAC menu item (<strong>ticket-monitoring</strong> or <strong>kerisi-ticket-monitoring</strong>).</li>
          <li>For Super Admin, monitoring/log routes are consolidated under <strong>Administration</strong> to avoid duplicate menu entries.</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">4. Setup &amp; Configuration</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li>Configure the OpenAI API key in <code class="px-1 py-0.5 bg-gray-100 rounded text-xs">.env</code>.</li>
          <li>Run <strong>Setup AI assistants</strong> on first use — creates the vector store and assistants (<strong>SELAR</strong> &amp; <strong>AINA</strong>).</li>
          <li>Run <strong>Upgrade Assistant</strong> when tools or configuration change.</li>
          <li>Copy the vector store ID and assistant ID to <code class="px-1 py-0.5 bg-gray-100 rounded text-xs">.env</code> after setup.</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">5. User Roles &amp; Access</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li><strong>Level 0 — Super Admin</strong>: full system scope (all users, all menu, all data).</li>
          <li><strong>Level 1 — Admin Internal</strong>: can manage and view Level 2/3/4 within their hierarchy tree.</li>
          <li><strong>Level 2 — Admin External</strong>: can manage and view only Agent/User under their own external tree.</li>
          <li><strong>Level 3 — Agent</strong>: operational support user under assigned branch.</li>
          <li><strong>Level 4 — User / Requester</strong>: end user for ticket and user chat workflows.</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">6. Multi-Level Tree Policy (Important)</h3>
        <p class="text-sm text-gray-600 mb-2">
          Access is controlled by both RBAC permission and hierarchy tree. The tree uses manager-child relations (who manages whom).
        </p>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li><strong>L2 (External Admin)</strong> can only see descendants under their own branch (agent/user under that external).</li>
          <li><strong>L1 (Internal Admin)</strong> can see descendants under internal branch: Level 2 + Level 3 + Level 4, including direct Level 3/4.</li>
          <li><strong>L0 (Super Admin)</strong> can see all branches.</li>
          <li>This scope applies across <strong>menu visibility, user data, notification admin targets, ticket listing, and support chat/session listing</strong>.</li>
          <li>Even if a role has permission, users cannot access data outside their hierarchy branch.</li>
        </ul>

        <h3 class="text-base font-medium text-gray-800 mt-6 mb-2">7. Best Practices</h3>
        <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
          <li>Keep documents up to date — update the User Manual when the system changes.</li>
          <li>Organise documents by module for better search results.</li>
          <li>Do not upload sensitive or confidential information to the Knowledge Base.</li>
          <li>Monitor sync logs for failures and retry if needed.</li>
        </ul>
      </article>
    </div>
  </AdminLayout>
</template>
