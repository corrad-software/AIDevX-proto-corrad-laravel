<?php

namespace App\Enums;

class Permission
{
    // Posts
    const POSTS_VIEW = 'posts.view';

    const POSTS_CREATE = 'posts.create';

    const POSTS_EDIT = 'posts.edit';

    const POSTS_DELETE = 'posts.delete';

    // Pages
    const PAGES_VIEW = 'pages.view';

    const PAGES_CREATE = 'pages.create';

    const PAGES_EDIT = 'pages.edit';

    const PAGES_DELETE = 'pages.delete';

    // Media
    const MEDIA_VIEW = 'media.view';

    const MEDIA_UPLOAD = 'media.upload';

    const MEDIA_DELETE = 'media.delete';

    // Users
    const USERS_VIEW = 'users.view';

    const USERS_CREATE = 'users.create';

    const USERS_EDIT = 'users.edit';

    const USERS_DELETE = 'users.delete';

    // Roles
    const ROLES_VIEW = 'roles.view';

    const ROLES_CREATE = 'roles.create';

    const ROLES_EDIT = 'roles.edit';

    const ROLES_DELETE = 'roles.delete';

    // Settings
    const SETTINGS_VIEW = 'settings.view';

    const SETTINGS_EDIT = 'settings.edit';

    // Menus
    const MENUS_VIEW = 'menus.view';

    const MENUS_EDIT = 'menus.edit';

    // Audit
    const AUDIT_READ = 'audit.read';

    // Knowledge Base
    const KNOWLEDGE_VIEW = 'knowledge.view';

    const KNOWLEDGE_MANAGE = 'knowledge.manage';

    // Chat
    const CHAT_USE = 'chat.use';

    const CHAT_ADMIN = 'chat.admin';

    // Customers (for registration / end user setup)
    const CUSTOMERS_VIEW = 'customers.view';

    const CUSTOMERS_CREATE = 'customers.create';

    const CUSTOMERS_EDIT = 'customers.edit';

    const CUSTOMERS_DELETE = 'customers.delete';

    /** In-app + email notification administration (all users' rows, resend, delete, broadcast) */
    const NOTIFICATIONS_ADMIN = 'notifications.admin';

    // Internal Ticket module
    const TICKETS_VIEW = 'tickets.view';

    const TICKETS_CREATE = 'tickets.create';

    const TICKETS_EDIT = 'tickets.edit';

    const TICKETS_DELETE = 'tickets.delete';

    const TICKETS_ASSIGN = 'tickets.assign';

    const TICKETS_RESPOND = 'tickets.respond';

    /**
     * Menu item IDs that require specific permission(s).
     * If user has menu_access to an item, they get the implied permission(s).
     * Value can be string or array of permissions.
     */
    public static function menuPermissionMap(): array
    {
        return [
            'kerisi-chat' => self::CHAT_USE,
            'kerisi-user-chat' => self::CHAT_USE,
            'kerisi-knowledge' => [self::KNOWLEDGE_VIEW, self::KNOWLEDGE_MANAGE],
            'platform-messaging-notifications' => self::NOTIFICATIONS_ADMIN,
            'platform-notifications' => self::NOTIFICATIONS_ADMIN,
            'ticket-365-log' => [self::TICKETS_VIEW, self::TICKETS_RESPOND],
            'kerisi-ticket' => [self::TICKETS_VIEW, self::TICKETS_RESPOND],
        ];
    }

    public static function all(): array
    {
        return [
            self::POSTS_VIEW, self::POSTS_CREATE, self::POSTS_EDIT, self::POSTS_DELETE,
            self::PAGES_VIEW, self::PAGES_CREATE, self::PAGES_EDIT, self::PAGES_DELETE,
            self::MEDIA_VIEW, self::MEDIA_UPLOAD, self::MEDIA_DELETE,
            self::USERS_VIEW, self::USERS_CREATE, self::USERS_EDIT, self::USERS_DELETE,
            self::ROLES_VIEW, self::ROLES_CREATE, self::ROLES_EDIT, self::ROLES_DELETE,
            self::SETTINGS_VIEW, self::SETTINGS_EDIT,
            self::MENUS_VIEW, self::MENUS_EDIT,
            self::AUDIT_READ,
            self::KNOWLEDGE_VIEW, self::KNOWLEDGE_MANAGE,
            self::CHAT_USE, self::CHAT_ADMIN,
            self::CUSTOMERS_VIEW, self::CUSTOMERS_CREATE, self::CUSTOMERS_EDIT, self::CUSTOMERS_DELETE,
            self::NOTIFICATIONS_ADMIN,
            self::TICKETS_VIEW, self::TICKETS_CREATE, self::TICKETS_EDIT, self::TICKETS_DELETE, self::TICKETS_ASSIGN, self::TICKETS_RESPOND,
        ];
    }
}
