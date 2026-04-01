<?php

namespace Database\Seeders;

use App\Enums\UserLevel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminRole = Role::where('name', 'admin')->firstOrFail();

        // Plain password: User model uses `password` => `hashed` cast (Hash::make on save).
        // Override via .env: ADMIN_EMAIL, ADMIN_PASSWORD, ADMIN_NAME.
        $adminEmail = env('ADMIN_EMAIL', 'admin@example.com') ?: 'admin@example.com';
        $adminPassword = env('ADMIN_PASSWORD') ?: 'admin12345';

        // Roles live on `role_user` pivot (migration 2026_03_22_000002 dropped users.role_id).
        $user = User::updateOrCreate(
            ['email' => $adminEmail],
            [
                'name' => env('ADMIN_NAME', 'Administrator') ?: 'Administrator',
                'password' => $adminPassword,
                'is_active' => true,
                'role' => 'admin',
                'user_level' => UserLevel::SUPER_ADMIN,
                'email_verified_at' => now(),
            ]
        );
        $user->roles()->sync([$adminRole->id]);
    }
}
