<?php

use App\Http\Controllers\Api\AdminNotificationController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DatabaseExplorerController;
use App\Http\Controllers\Api\DevelopersGuideController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\KnowledgeController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\TicketMonitoringController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Diagnostics (no auth) — DB + migrations; Isu #3
Route::get('/health', HealthController::class);

// Public routes (no auth)
Route::prefix('public')->group(function () {
    Route::get('/site', [PublicController::class, 'site']);
    Route::get('/pages/frontpage', [PublicController::class, 'frontpage']);
    Route::get('/pages/{slug}', [PublicController::class, 'pageBySlug']);
    Route::get('/customers/active', [CustomerController::class, 'listActive']);
});

// Auth routes
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
    Route::post('/resend-verification', [AuthController::class, 'resendVerification'])->middleware('throttle:3,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/me', [AuthController::class, 'updateProfile']);
        Route::post('/password', [AuthController::class, 'changePassword']);
        Route::post('/avatar', [AuthController::class, 'uploadAvatar']);
        Route::delete('/avatar', [AuthController::class, 'removeAvatar']);
        Route::post('/impersonate', [AuthController::class, 'impersonate']);
        Route::post('/stop-impersonate', [AuthController::class, 'stopImpersonate']);
        Route::get('/impersonate-users', [AuthController::class, 'impersonateUsers']);
    });
});

// Settings GET is public (used by SPA before auth)
Route::get('/settings', [SettingController::class, 'index']);

// Protected admin routes
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('posts', PostController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('pages', PageController::class);
    // Must be before users resource so "agent-picklist" is not captured as {user}
    Route::get('/users/agent-picklist', [UserController::class, 'agentPicklist']);
    Route::apiResource('users', UserController::class);
    Route::apiResource('roles', RoleController::class);
    Route::apiResource('customers', CustomerController::class);

    Route::get('/media', [MediaController::class, 'index']);
    Route::post('/media/upload', [MediaController::class, 'upload']);
    Route::put('/media/{media}', [MediaController::class, 'update']);
    Route::delete('/media/{media}', [MediaController::class, 'destroy']);

    Route::put('/settings', [SettingController::class, 'update']);
    Route::get('/settings/lookups', [SettingController::class, 'lookups']);
    Route::put('/settings/lookups', [SettingController::class, 'updateLookups']);
    Route::get('/settings/admin-menu-prefs', [SettingController::class, 'adminMenuPrefs']);
    Route::put('/settings/admin-menu-prefs', [SettingController::class, 'updateAdminMenuPrefs']);
    Route::get('/settings/storefront-menu', [SettingController::class, 'storefrontMenu']);
    Route::put('/settings/storefront-menu', [SettingController::class, 'updateStorefrontMenu']);

    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('/dashboard/analytics', [DashboardController::class, 'analytics']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/mark-read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead']);

    Route::prefix('notification-admin')->middleware('permission:notifications.admin')->group(function () {
        Route::get('/', [AdminNotificationController::class, 'index']);
        Route::post('/send', [AdminNotificationController::class, 'send']);
        Route::post('/{id}/resend-email', [AdminNotificationController::class, 'resendEmail'])->whereNumber('id');
        Route::delete('/{id}', [AdminNotificationController::class, 'destroy'])->whereNumber('id');
    });

    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    Route::prefix('database')->middleware('permission:database.manage')->group(function () {
        Route::get('/tables', [DatabaseExplorerController::class, 'tables']);
        Route::get('/tables/{table}/schema', [DatabaseExplorerController::class, 'schema'])->where('table', '[a-zA-Z0-9_]+');
        Route::get('/tables/{table}/rows', [DatabaseExplorerController::class, 'rows'])->where('table', '[a-zA-Z0-9_]+');
        Route::post('/tables/{table}/rows', [DatabaseExplorerController::class, 'storeRow'])->where('table', '[a-zA-Z0-9_]+');
        Route::put('/tables/{table}/rows', [DatabaseExplorerController::class, 'updateRow'])->where('table', '[a-zA-Z0-9_]+');
        Route::delete('/tables/{table}/rows', [DatabaseExplorerController::class, 'destroyRow'])->where('table', '[a-zA-Z0-9_]+');
    });

    Route::get('/developers-guide', [DevelopersGuideController::class, 'show']);
    Route::put('/developers-guide', [DevelopersGuideController::class, 'update']);

    // Knowledge Base
    Route::get('/knowledge/modules', [KnowledgeController::class, 'modules']);
    Route::post('/knowledge/setup', [KnowledgeController::class, 'setup']);
    Route::post('/knowledge/upgrade-assistant', [KnowledgeController::class, 'upgradeAssistant'])->middleware('permission:knowledge.manage');
    Route::post('/knowledge/setup-user-chat-assistant', [KnowledgeController::class, 'setupUserChatAssistant'])->middleware('permission:knowledge.manage');
    Route::get('/knowledge/db-status', [KnowledgeController::class, 'dbStatus'])->middleware('permission:knowledge.manage');
    Route::get('/knowledge/desk365-status', [KnowledgeController::class, 'desk365Status'])->middleware('permission:knowledge.manage');
    Route::get('/knowledge/desk365-tickets', [KnowledgeController::class, 'desk365Tickets'])->middleware('permission:knowledge.manage');
    Route::post('/knowledge/sync-desk365-tickets', [KnowledgeController::class, 'syncDesk365Tickets'])->middleware('permission:knowledge.manage');
    Route::post('/knowledge/sync-schema', [KnowledgeController::class, 'syncDatabaseSchema'])->middleware('permission:knowledge.manage');
    Route::post('/knowledge/sync-lookup', [KnowledgeController::class, 'syncKnowledgeLookup'])->middleware('permission:knowledge.manage');
    Route::post('/knowledge/sync-menu-access', [KnowledgeController::class, 'syncKnowledgeMenuAccess'])->middleware('permission:knowledge.manage');
    Route::post('/knowledge/sync-pages', [KnowledgeController::class, 'syncKnowledgePages'])->middleware('permission:knowledge.manage');
    Route::post('/knowledge/sync-bl', [KnowledgeController::class, 'syncKnowledgeBl'])->middleware('permission:knowledge.manage');
    Route::get('/knowledge/extract-sync-logs', [KnowledgeController::class, 'knowledgeExtractSyncLogs']);
    Route::get('/knowledge/desk365-sync-logs', [KnowledgeController::class, 'desk365SyncLogs']);
    Route::get('/knowledge/internal-tickets-preview', [KnowledgeController::class, 'internalTicketsPreview'])->middleware('permission:knowledge.manage');
    Route::post('/knowledge/sync-internal-tickets', [KnowledgeController::class, 'syncInternalTickets'])->middleware('permission:knowledge.manage');
    Route::get('/knowledge/internal-ticket-sync-logs', [KnowledgeController::class, 'internalTicketSyncLogs']);
    Route::get('/knowledge', [KnowledgeController::class, 'index'])->middleware('permission:knowledge.view');
    Route::post('/knowledge/upload', [KnowledgeController::class, 'upload'])->middleware('permission:knowledge.manage');
    Route::delete('/knowledge/{id}', [KnowledgeController::class, 'destroy'])->middleware('permission:knowledge.manage');

    // Ticket list & detail — accessible by all authenticated users (agent, admin, user)
    Route::get('/chat/tickets', [ChatController::class, 'tickets']);
    Route::get('/chat/tickets/{ticketId}', [ChatController::class, 'ticketDetail']);

    // Internal support tickets (AFSA)
    Route::get('/tickets/monitoring', [TicketMonitoringController::class, 'index']);
    Route::get('/tickets', [SupportTicketController::class, 'index']);
    Route::post('/tickets', [SupportTicketController::class, 'store']);
    Route::get('/tickets/{id}', [SupportTicketController::class, 'show'])->whereNumber('id');
    Route::put('/tickets/{id}', [SupportTicketController::class, 'update'])->whereNumber('id');
    Route::patch('/tickets/{id}', [SupportTicketController::class, 'update'])->whereNumber('id');
    Route::delete('/tickets/{id}', [SupportTicketController::class, 'destroy'])->whereNumber('id');
    Route::post('/tickets/{id}/assign', [SupportTicketController::class, 'assign'])->whereNumber('id');
    Route::post('/tickets/{id}/agent-reply-suggest', [SupportTicketController::class, 'agentReplySuggestion'])
        ->whereNumber('id')
        ->middleware('throttle:20,1');
    Route::post('/tickets/{id}/reply', [SupportTicketController::class, 'reply'])->whereNumber('id');
    Route::post('/tickets/{id}/reject-ai', [SupportTicketController::class, 'rejectAi'])->whereNumber('id');
    Route::post('/tickets/{id}/close', [SupportTicketController::class, 'close'])->whereNumber('id');

    // KERISI Support Chat (agent, admin, super_admin only)
    Route::middleware(['permission:chat.use', 'support_chat_access'])->group(function () {
        Route::post('/chat/sessions', [ChatController::class, 'newSession']);
        Route::put('/chat/sessions/{id}', [ChatController::class, 'updateSession']);
        Route::post('/chat/sessions/{id}/messages', [ChatController::class, 'sendMessage']);
        Route::get('/chat/sessions/{id}', [ChatController::class, 'getSession']);
        Route::get('/chat/sessions', [ChatController::class, 'mySessions']);
        Route::delete('/chat/sessions/{id}', [ChatController::class, 'deleteSession']);
        Route::post('/chat/sessions/{id}/favorite', [ChatController::class, 'toggleSessionFavorite']);
        Route::get('/chat/sessions/{id}/messages/search', [ChatController::class, 'searchMessages']);
        Route::get('/chat/favorites', [ChatController::class, 'favorites']);
        Route::post('/chat/messages/{id}/favorite', [ChatController::class, 'toggleFavorite']);
        Route::get('/chat/suggestions', [ChatController::class, 'suggestions']);
    });
    Route::get('/chat/all-sessions', [ChatController::class, 'allSessions'])->middleware('permission:chat.admin');

    // KERISI User Chat (user level only; no ticket, no SQL/schema in AI)
    Route::middleware(['permission:chat.use', 'user_chat_access'])->prefix('chat/user')->group(function () {
        Route::post('/sessions', [ChatController::class, 'newUserChatSession']);
        Route::put('/sessions/{id}', [ChatController::class, 'updateUserChatSession']);
        Route::post('/sessions/{id}/messages', [ChatController::class, 'sendUserChatMessage']);
        Route::get('/sessions/{id}', [ChatController::class, 'getUserChatSession']);
        Route::get('/sessions', [ChatController::class, 'myUserChatSessions']);
        Route::delete('/sessions/{id}', [ChatController::class, 'deleteUserChatSession']);
        Route::post('/sessions/{id}/favorite', [ChatController::class, 'toggleUserChatSessionFavorite']);
        Route::get('/sessions/{id}/messages/search', [ChatController::class, 'searchUserChatMessages']);
        Route::get('/favorites', [ChatController::class, 'userChatFavorites']);
        Route::post('/messages/{id}/favorite', [ChatController::class, 'toggleUserChatMessageFavorite']);
        Route::get('/suggestions', [ChatController::class, 'userChatSuggestions']);
    });
});
