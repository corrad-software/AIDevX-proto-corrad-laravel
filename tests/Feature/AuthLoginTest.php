<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_succeeds_with_valid_credentials(): void
    {
        $role = Role::create([
            'name' => 'admin',
            'description' => 'Admin',
            'permissions' => [],
            'menu_access' => null,
        ]);
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'admin12345',
            'user_level' => 'super_admin',
            'email_verified_at' => now(),
        ]);
        $user->roles()->sync([$role->id]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'admin12345',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.user.email', 'admin@example.com');
    }

    /**
     * Regression: stale session had impersonate_user_id but no auth → ImpersonateMiddleware crashed on null user.
     */
    public function test_login_does_not_500_when_session_has_stale_impersonation_keys(): void
    {
        $role = Role::create([
            'name' => 'admin',
            'description' => 'Admin',
            'permissions' => [],
            'menu_access' => null,
        ]);
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'admin12345',
            'user_level' => 'super_admin',
            'email_verified_at' => now(),
        ]);
        $user->roles()->sync([$role->id]);

        $response = $this->withSession([
            'impersonate_user_id' => 99999,
            'impersonated_by' => 1,
        ])->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'admin12345',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.user.email', 'admin@example.com');
    }
}
