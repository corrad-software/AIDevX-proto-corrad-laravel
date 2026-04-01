import { createRouter, createWebHistory } from "vue-router";
import type { RouteLocationGeneric, RouteRecordRaw } from "vue-router";

import DashboardView from "@/views/DashboardView.vue";
import MainDashboardView from "@/views/MainDashboardView.vue";
import KitchenChartsView from "@/views/KitchenChartsView.vue";
import KitchenFormsView from "@/views/KitchenFormsView.vue";
import LoginView from "@/views/LoginView.vue";
import RegisterView from "@/views/RegisterView.vue";
import VerifyEmailView from "@/views/VerifyEmailView.vue";
import MediaLibraryView from "@/views/MediaLibraryView.vue";
import KitchenSinkView from "@/views/KitchenSinkView.vue";
import PageEditorView from "@/views/PageEditorView.vue";
import PagesListView from "@/views/PagesListView.vue";
import PostEditorView from "@/views/PostEditorView.vue";
import PostsListView from "@/views/PostsListView.vue";
import CategoriesListView from "@/views/CategoriesListView.vue";
import CategoryEditorView from "@/views/CategoryEditorView.vue";
import DatabaseSchemaView from "@/views/DatabaseSchemaView.vue";
import DevelopersGuideView from "@/views/DevelopersGuideView.vue";
import ApiManagementView from "@/views/ApiManagementView.vue";
import MenusView from "@/views/MenusView.vue";
import StorefrontMenuView from "@/views/StorefrontMenuView.vue";
import WebfrontSettingsView from "@/views/WebfrontSettingsView.vue";
import AuditLogsView from "@/views/AuditLogsView.vue";
import QueueMonitorView from "@/views/QueueMonitorView.vue";
import ComingSoonView from "@/views/ComingSoonView.vue";
import RolesView from "@/views/RolesView.vue";
import SettingsView from "@/views/SettingsView.vue";
import SystemInfoView from "@/views/SystemInfoView.vue";
import UsersView from "@/views/UsersView.vue";
import UserEditView from "@/views/UserEditView.vue";
import CustomersView from "@/views/CustomersView.vue";
import CustomerEditorView from "@/views/CustomerEditorView.vue";
import StorefrontHomeView from "@/views/StorefrontHomeView.vue";
import StorefrontPageView from "@/views/StorefrontPageView.vue";
import KerisiChatView from "@/views/KerisiChatView.vue";
import KerisiUserChatView from "@/views/KerisiUserChatView.vue";
import KerisiTicketView from "@/views/KerisiTicketView.vue";
import KerisiGuideView from "@/views/KerisiGuideView.vue";
import KerisiAboutView from "@/views/KerisiAboutView.vue";
import KnowledgeManageView from "@/views/KnowledgeManageView.vue";
import Desk365SyncLogView from "@/views/Desk365SyncLogView.vue";
import InternalTicketSyncLogView from "@/views/InternalTicketSyncLogView.vue";
import KnowledgeExtractSyncLogView from "@/views/KnowledgeExtractSyncLogView.vue";
import TicketMonitoringView from "@/views/TicketMonitoringView.vue";
import ForgotPasswordView from "@/views/ForgotPasswordView.vue";
import ResetPasswordView from "@/views/ResetPasswordView.vue";
import ResendVerificationView from "@/views/ResendVerificationView.vue";
import NotificationsListView from "@/views/NotificationsListView.vue";
import NotificationCenterAdminView from "@/views/NotificationCenterAdminView.vue";
import DatabaseExplorerView from "@/views/DatabaseExplorerView.vue";
import { useAuthStore } from "@/stores/auth";
import { useSiteStore } from "@/stores/site";

const legacyAdminPaths = [
  "/login",
  "/portal/dashboard",
  "/posts",
  "/posts/new",
  "/posts/:id",
  "/categories",
  "/categories/new",
  "/categories/:id",
  "/pages",
  "/pages/new",
  "/pages/:id",
  "/media",
  "/menus",
  "/webfront-menu",
  "/webfront-settings",
  "/storefront-menu",
  "/kitchen-sink",
  "/kitchen-sink/forms",
  "/kitchen-sink/charts",
  "/development/database-schema",
  "/development/api-management",
  "/profile",
  "/settings",
  "/settings/users",
  "/settings/users/new",
  "/settings/users/:id",
  "/settings/roles",
  "/settings/audit-logs",
  "/settings/queue-monitor",
  "/settings/system",
];

// Backward-compat redirects: old /admin/settings/* → new /admin/platform/* paths
const settingsRedirects: RouteRecordRaw[] = [
  { path: "/admin/settings/users", redirect: "/admin/platform/identity/users" },
  { path: "/admin/settings/users/new", redirect: "/admin/platform/identity/users/new" },
  { path: "/admin/settings/users/:id", redirect: (to: RouteLocationGeneric) => `/admin/platform/identity/users/${String(to.params.id ?? "")}` },
  { path: "/admin/settings/roles", redirect: "/admin/platform/identity/roles" },
  { path: "/admin/settings/audit-logs", redirect: "/admin/platform/observability/audit-trail" },
  { path: "/admin/settings/queue-monitor", redirect: "/admin/platform/queue" },
];

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: "/admin/login", name: "login", component: LoginView, meta: { guestOnly: true, title: "Login" } },
    { path: "/admin/register", name: "register", component: RegisterView, meta: { guestOnly: true, title: "Register" } },
    { path: "/admin/forgot-password", name: "forgot-password", component: ForgotPasswordView, meta: { guestOnly: true, title: "Forgot password" } },
    { path: "/admin/reset-password", name: "reset-password", component: ResetPasswordView, meta: { guestOnly: true, title: "Reset password" } },
    {
      path: "/admin/resend-verification",
      name: "resend-verification",
      component: ResendVerificationView,
      meta: { guestOnly: true, title: "Resend verification" },
    },
    { path: "/verify-email", name: "verify-email", component: VerifyEmailView, meta: { guestOnly: true, title: "Verify email" } },
    { path: "/admin", name: "main-dashboard", component: MainDashboardView, meta: { requiresAuth: true, title: "Main Dashboard" } },
    { path: "/admin/portal/dashboard", name: "dashboard", component: DashboardView, meta: { requiresAuth: true, title: "Dashboard" } },
    { path: "/admin/posts", name: "posts", component: PostsListView, meta: { requiresAuth: true, title: "Posts" } },
    { path: "/admin/posts/new", name: "post-create", component: PostEditorView, meta: { requiresAuth: true, title: "New Post" } },
    { path: "/admin/posts/:id", name: "post-edit", component: PostEditorView, meta: { requiresAuth: true, title: "Edit Post" } },
    { path: "/admin/categories", name: "categories", component: CategoriesListView, meta: { requiresAuth: true, title: "Categories" } },
    { path: "/admin/categories/new", name: "category-create", component: CategoryEditorView, meta: { requiresAuth: true, title: "New Category" } },
    { path: "/admin/categories/:id", name: "category-edit", component: CategoryEditorView, meta: { requiresAuth: true, title: "Edit Category" } },
    { path: "/admin/pages", name: "pages", component: PagesListView, meta: { requiresAuth: true, title: "Pages" } },
    { path: "/admin/pages/new", name: "page-create", component: PageEditorView, meta: { requiresAuth: true, title: "New Page" } },
    { path: "/admin/pages/:id", name: "page-edit", component: PageEditorView, meta: { requiresAuth: true, title: "Edit Page" } },
    { path: "/admin/media", name: "media", component: MediaLibraryView, meta: { requiresAuth: true, title: "Media" } },
    { path: "/admin/webfront-menu", name: "storefront-menu", component: StorefrontMenuView, meta: { requiresAuth: true, title: "Menus" } },
    { path: "/admin/storefront-menu", redirect: "/admin/webfront-menu" },
    { path: "/admin/webfront-settings", name: "webfront-settings", component: WebfrontSettingsView, meta: { requiresAuth: true, title: "Settings" } },
    { path: "/admin/menus", name: "menus", component: MenusView, meta: { requiresAuth: true, title: "Menus" } },
    { path: "/admin/kitchen-sink", name: "kitchen-sink", component: KitchenSinkView, meta: { requiresAuth: true, title: "Kitchen Sink" } },
    { path: "/admin/kitchen-sink/forms", name: "kitchen-forms", component: KitchenFormsView, meta: { requiresAuth: true, title: "Forms" } },
    { path: "/admin/kitchen-sink/charts", name: "kitchen-charts", component: KitchenChartsView, meta: { requiresAuth: true, title: "Charts" } },
    { path: "/admin/development/developers-guide", name: "developers-guide", component: DevelopersGuideView, meta: { requiresAuth: true, title: "Developers Guide" } },
    { path: "/admin/development/database-schema", name: "database-schema", component: DatabaseSchemaView, meta: { requiresAuth: true, title: "Database Schema" } },
    { path: "/admin/development/api-explorer", name: "api-explorer", component: ApiManagementView, meta: { requiresAuth: true, title: "API Explorer" } },
    { path: "/admin/development/api-management", redirect: "/admin/development/api-explorer" },
    {
      path: "/admin/profile",
      name: "profile",
      meta: { requiresAuth: true },
      beforeEnter: async () => {
        const auth = useAuthStore();
        await auth.initialize();
        if (auth.user?.id) return `/admin/platform/identity/users/${auth.user.id}`;
        return { name: "login" };
      },
      component: { template: "" },
    },

    // ── Administration ──
    { path: "/admin/settings", name: "settings", component: SettingsView, meta: { requiresAuth: true, title: "Settings" } },
    { path: "/admin/settings/system", name: "settings-system", component: SystemInfoView, meta: { requiresAuth: true, title: "System Info" } },

    // ── Core Platform: Identity & Access ──
    { path: "/admin/platform/identity", redirect: "/admin/platform/identity/users" },
    { path: "/admin/platform/identity/users", name: "platform-users", component: UsersView, meta: { requiresAuth: true, title: "Users" } },
    { path: "/admin/platform/identity/users/new", name: "platform-user-create", component: UserEditView, meta: { requiresAuth: true, title: "New User" } },
    { path: "/admin/platform/identity/users/:id", name: "platform-user-edit", component: UserEditView, meta: { requiresAuth: true, title: "Edit User" } },
    { path: "/admin/platform/identity/roles", name: "platform-rbac", component: RolesView, meta: { requiresAuth: true, title: "RBAC" } },
    { path: "/admin/platform/identity/tokens", name: "platform-tokens", component: ComingSoonView, meta: { requiresAuth: true, title: "Token Management" } },
    { path: "/admin/platform/customers", name: "platform-customers", component: CustomersView, meta: { requiresAuth: true, title: "Customer Setup" } },
    { path: "/admin/platform/customers/new", name: "platform-customer-create", component: CustomerEditorView, meta: { requiresAuth: true, title: "New Customer" } },
    { path: "/admin/platform/customers/:id", name: "platform-customer-edit", component: CustomerEditorView, meta: { requiresAuth: true, title: "Edit Customer" } },

    // ── Core Platform: Observability (Grafana) ──
    { path: "/admin/platform/observability", redirect: "/admin/platform/observability/audit-trail" },
    { path: "/admin/platform/observability/audit-trail", name: "platform-audit-trail", component: AuditLogsView, meta: { requiresAuth: true, title: "Audit Trail" } },
    { path: "/admin/platform/observability/activity-log", name: "platform-activity-log", component: ComingSoonView, meta: { requiresAuth: true, title: "Activity Log" } },
    { path: "/admin/platform/observability/logging", name: "platform-logging", component: ComingSoonView, meta: { requiresAuth: true, title: "Logging" } },
    { path: "/admin/platform/observability/errors", name: "platform-error-tracking", component: ComingSoonView, meta: { requiresAuth: true, title: "Error Tracking" } },
    { path: "/admin/platform/observability/monitoring", name: "platform-monitoring", component: ComingSoonView, meta: { requiresAuth: true, title: "Monitoring" } },

    // ── Core Platform: Queue (Laravel Queue) ──
    { path: "/admin/platform/queue", name: "platform-queue", component: QueueMonitorView, meta: { requiresAuth: true, title: "Queue" } },
    { path: "/admin/platform/queue/failed", name: "platform-queue-failed", component: ComingSoonView, meta: { requiresAuth: true, title: "Failed Jobs" } },
    { path: "/admin/platform/queue/scheduled", name: "platform-queue-scheduled", component: ComingSoonView, meta: { requiresAuth: true, title: "Scheduled Jobs" } },

    // ── Core Platform: Messaging ──
    { path: "/admin/platform/messaging", redirect: "/admin/platform/messaging/event-bus" },
    { path: "/admin/platform/messaging/event-bus", name: "platform-event-bus", component: ComingSoonView, meta: { requiresAuth: true, title: "Event Bus" } },
    { path: "/admin/platform/messaging/notifications", name: "platform-messaging-notifications", component: ComingSoonView, meta: { requiresAuth: true, title: "Notifications" } },

    // ── Backward-compat redirects from old governance/communication paths ──
    { path: "/admin/platform/governance", redirect: "/admin/platform/observability/audit-trail" },
    { path: "/admin/platform/governance/audit-trail", redirect: "/admin/platform/observability/audit-trail" },
    { path: "/admin/platform/governance/activity-log", redirect: "/admin/platform/observability/activity-log" },
    { path: "/admin/platform/communication", redirect: "/admin/platform/messaging/notifications" },
    { path: "/admin/platform/communication/notifications", redirect: "/admin/platform/messaging/notifications" },
    { path: "/admin/platform/messaging/queue", redirect: "/admin/platform/queue" },
    { path: "/admin/platform/messaging/queue/failed", redirect: "/admin/platform/queue/failed" },
    { path: "/admin/platform/messaging/queue/scheduled", redirect: "/admin/platform/queue/scheduled" },

    // ── Core Platform: System Management ──
    { path: "/admin/platform/system", redirect: "/admin/platform/system/configuration" },
    { path: "/admin/platform/system/configuration", name: "platform-config", component: ComingSoonView, meta: { requiresAuth: true, title: "Configuration" } },
    { path: "/admin/platform/system/feature-flags", name: "platform-feature-flags", component: ComingSoonView, meta: { requiresAuth: true, title: "Feature Flags" } },
    { path: "/admin/platform/system/scheduler", name: "platform-scheduler", component: ComingSoonView, meta: { requiresAuth: true, title: "Scheduler" } },

    // ── Core Platform: Storage ──
    { path: "/admin/platform/storage", redirect: "/admin/platform/storage/media" },
    { path: "/admin/platform/storage/media", name: "platform-file-media", component: ComingSoonView, meta: { requiresAuth: true, title: "File / Media Management" } },

    // ── Core Platform: API Gateway (APISIX) ──
    { path: "/admin/platform/gateway", redirect: "/admin/platform/gateway/routes" },
    { path: "/admin/platform/gateway/routes", name: "platform-gateway-routes", component: ComingSoonView, meta: { requiresAuth: true, title: "Routes" } },
    { path: "/admin/platform/gateway/upstreams", name: "platform-gateway-upstreams", component: ComingSoonView, meta: { requiresAuth: true, title: "Upstreams" } },
    { path: "/admin/platform/gateway/consumers", name: "platform-gateway-consumers", component: ComingSoonView, meta: { requiresAuth: true, title: "Consumers" } },
    { path: "/admin/platform/gateway/plugins", name: "platform-gateway-plugins", component: ComingSoonView, meta: { requiresAuth: true, title: "Plugins" } },
    { path: "/admin/platform/gateway/ssl", name: "platform-gateway-ssl", component: ComingSoonView, meta: { requiresAuth: true, title: "SSL Certificates" } },
    { path: "/admin/platform/gateway/webhooks", name: "platform-webhooks", component: ComingSoonView, meta: { requiresAuth: true, title: "Webhooks" } },

    // ── Backward-compat redirects from old integration paths ──
    { path: "/admin/platform/integration", redirect: "/admin/platform/gateway/routes" },
    { path: "/admin/platform/integration/api", redirect: "/admin/platform/gateway/routes" },
    { path: "/admin/platform/integration/webhooks", redirect: "/admin/platform/gateway/webhooks" },

    // ── Core Platform: Desk365 & internal ticket AI sync logs ──
    { path: "/admin/platform/desk365", name: "platform-desk365", component: Desk365SyncLogView, meta: { requiresAuth: true, title: "Desk365 log" } },
    {
      path: "/admin/platform/ticket-log",
      name: "platform-ticket-log",
      component: InternalTicketSyncLogView,
      meta: { requiresAuth: true, title: "Ticket log" },
    },
    {
      path: "/admin/platform/knowledge-extract-log",
      name: "platform-knowledge-extract-log",
      component: KnowledgeExtractSyncLogView,
      meta: { requiresAuth: true, title: "Knowledge extract log" },
    },
    {
      path: "/admin/tickets/monitoring",
      name: "tickets-monitoring",
      component: TicketMonitoringView,
      meta: { requiresAuth: true, title: "Ticket monitoring" },
    },
    {
      path: "/admin/platform/notifications",
      name: "platform-notifications",
      component: NotificationCenterAdminView,
      meta: { requiresAuth: true, title: "Notification Center" },
    },
    {
      path: "/admin/platform/database",
      name: "platform-database",
      component: DatabaseExplorerView,
      meta: { requiresAuth: true, title: "Database" },
    },
    // ── Core Platform: AI Integration ──
    { path: "/admin/platform/ai", redirect: "/admin/platform/ai/providers" },
    { path: "/admin/platform/ai/providers", name: "platform-ai-providers", component: ComingSoonView, meta: { requiresAuth: true, title: "AI Providers" } },
    { path: "/admin/platform/ai/models", name: "platform-ai-models", component: ComingSoonView, meta: { requiresAuth: true, title: "AI Models" } },
    { path: "/admin/platform/ai/prompts", name: "platform-ai-prompts", component: ComingSoonView, meta: { requiresAuth: true, title: "Prompt Templates" } },
    { path: "/admin/platform/ai/usage", name: "platform-ai-usage", component: ComingSoonView, meta: { requiresAuth: true, title: "AI Usage & Billing" } },

    // ── AFSA: SELAR (Support) + AINA (User Chat) + KB / tickets / guide ──
    { path: "/admin/kerisi/chat", name: "kerisi-chat", component: KerisiChatView, meta: { requiresAuth: true, title: "SELAR — Support Chat" } },
    { path: "/admin/kerisi/user-chat", name: "kerisi-user-chat", component: KerisiUserChatView, meta: { requiresAuth: true, title: "AINA — User" } },
    { path: "/admin/kerisi/ticket", name: "kerisi-ticket", component: KerisiTicketView, meta: { requiresAuth: true, title: "Ticket" } },
    { path: "/admin/kerisi/notifications", name: "kerisi-notifications", component: NotificationsListView, meta: { requiresAuth: true, title: "Notifications" } },
    { path: "/admin/kerisi/guide", name: "kerisi-guide", component: KerisiGuideView, meta: { requiresAuth: true, title: "Guide" } },
    {
    path: "/admin/kerisi/about",
    name: "kerisi-about",
    component: KerisiAboutView,
    meta: { requiresAuth: true, title: "About KERISI" },
  },
    { path: "/admin/kerisi/knowledge", name: "kerisi-knowledge", component: KnowledgeManageView, meta: { requiresAuth: true, title: "Knowledge Base" } },

    // ── Backward-compat redirects from old settings paths ──
    ...settingsRedirects,

    ...legacyAdminPaths.map<RouteRecordRaw>((path) => ({
      path,
      redirect: (to: RouteLocationGeneric) => `/admin${to.fullPath}`,
    })),

    { path: "/", name: "storefront-home", component: StorefrontHomeView, meta: { title: "Webfront" } },
    { path: "/:slug", name: "storefront-page", component: StorefrontPageView, meta: { title: "Webfront" } },
  ],
});

router.beforeEach(async (to) => {
  const auth = useAuthStore();
  await auth.initialize();

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: "login" };
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: "main-dashboard" };
  }

  return true;
});

router.afterEach((to) => {
  const site = useSiteStore();
  const pageTitle = (to.meta.title as string) || "Admin";
  site.setDocumentTitle(pageTitle);
});

export default router;
