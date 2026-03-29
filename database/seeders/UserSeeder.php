<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $superAdminRole = Role::where('name', 'super_admin')->firstOrFail();

        User::updateOrCreate(
            ['email' => env('SUPER_ADMIN_EMAIL', 'superadmin@example.com')],
            [
                'name' => env('SUPER_ADMIN_NAME', 'Super Administrator'),
                'password' => Hash::make(env('SUPER_ADMIN_PASSWORD', 'superadmin12345')),
                'is_active' => true,
                'role' => 'super_admin',
                'role_id' => $superAdminRole->id,
            ]
        );

        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.com')],
            [
                'name' => env('ADMIN_NAME', 'Administrator'),
                'password' => Hash::make(env('ADMIN_PASSWORD', 'admin12345')),
                'is_active' => true,
                'role' => 'admin',
                'role_id' => $adminRole->id,
            ]
        );
    }
}
