<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Role::updateOrCreate(
            ['name' => 'admin'],
            [
                'description' => 'Full system access',
                'permissions' => [
                    'posts.view', 'posts.create', 'posts.edit', 'posts.delete',
                    'pages.view', 'pages.create', 'pages.edit', 'pages.delete',
                    'media.view', 'media.upload', 'media.delete',
                    'users.view', 'users.create', 'users.edit', 'users.delete',
                    'roles.view', 'roles.create', 'roles.edit', 'roles.delete',
                    'settings.view', 'settings.edit',
                    'menus.view', 'menus.edit',
                    'knowledge.view', 'knowledge.manage',
                    'chat.use', 'chat.admin',
                    'customers.view', 'customers.create', 'customers.edit', 'customers.delete',
                    'notifications.admin',
                    'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.delete', 'tickets.assign', 'tickets.respond',
                ],
            ]
        );
    }
}
