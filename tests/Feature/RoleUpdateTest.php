<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_put_update_persists_permissions_and_menu_access(): void
    {
        $user = User::factory()->create(['user_level' => 'super_admin']);

        $role = Role::create([
            'name' => 'Test Role',
            'description' => 'd',
            'permissions' => ['posts.view'],
            'menu_access' => ['main-dashboard'],
        ]);

        $response = $this->actingAs($user)->putJson('/api/roles/'.$role->id, [
            'name' => 'Test Role',
            'description' => 'd',
            'permissions' => ['posts.view', 'pages.view'],
            'menu_access' => ['main-dashboard', 'kerisi-chat'],
        ]);

        $response->assertOk();
        $role->refresh();
        $this->assertSame(['posts.view', 'pages.view'], $role->permissions);
        $this->assertSame(['main-dashboard', 'kerisi-chat'], $role->menu_access);
    }

    public function test_patch_update_persists_like_put(): void
    {
        $user = User::factory()->create(['user_level' => 'super_admin']);

        $role = Role::create([
            'name' => 'Patch Role',
            'description' => 'x',
            'permissions' => ['posts.view'],
            'menu_access' => null,
        ]);

        $response = $this->actingAs($user)->patchJson('/api/roles/'.$role->id, [
            'name' => 'Patch Role',
            'description' => 'x',
            'permissions' => ['posts.view', 'media.view'],
        ]);

        $response->assertOk();
        $role->refresh();
        $this->assertSame(['posts.view', 'media.view'], $role->permissions);
    }

    public function test_put_empty_json_returns_bad_request(): void
    {
        $user = User::factory()->create(['user_level' => 'super_admin']);
        $role = Role::create([
            'name' => 'Empty Body Role',
            'description' => 'd',
            'permissions' => ['posts.view'],
            'menu_access' => null,
        ]);

        $response = $this->actingAs($user)->call(
            'PUT',
            '/api/roles/'.$role->id,
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'CONTENT_TYPE' => 'application/json',
                'Accept' => 'application/json',
            ]),
            '{}',
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'BAD_REQUEST');
    }

    public function test_put_update_without_permissions_key_does_not_wipe_permissions(): void
    {
        $user = User::factory()->create(['user_level' => 'super_admin']);

        $role = Role::create([
            'name' => 'Keep Perms',
            'description' => '',
            'permissions' => ['users.view', 'roles.view'],
            'menu_access' => null,
        ]);

        $response = $this->actingAs($user)->putJson('/api/roles/'.$role->id, [
            'name' => 'Keep Perms',
            'description' => 'updated desc',
        ]);

        $response->assertOk();
        $role->refresh();
        $this->assertSame(['users.view', 'roles.view'], $role->permissions);
        $this->assertSame('updated desc', $role->description);
    }

    /**
     * Empty description is common in the SPA (trim → ""). ConvertEmptyStringsToNull + NOT NULL
     * column previously caused a failed UPDATE misreported as duplicate name (409).
     */
    public function test_put_update_with_empty_description_string_succeeds(): void
    {
        $user = User::factory()->create(['user_level' => 'super_admin']);
        $unique = 'Empty Desc Role '.uniqid('', true);
        $role = Role::create([
            'name' => $unique,
            'description' => '',
            'permissions' => ['posts.view'],
            'menu_access' => ['main-dashboard'],
        ]);

        $response = $this->actingAs($user)->putJson('/api/roles/'.$role->id, [
            'name' => $unique,
            'description' => '',
            'permissions' => ['posts.view', 'pages.view'],
            'menu_access' => ['main-dashboard', 'kerisi-chat'],
        ]);

        $response->assertOk();
        $role->refresh();
        $this->assertSame('', $role->description);
        $this->assertSame(['posts.view', 'pages.view'], $role->permissions);
    }

    public function test_put_accepts_camel_case_menu_access_like_vue_client(): void
    {
        $user = User::factory()->create(['user_level' => 'internal_admin']);

        $unique = 'Camel Menu Role '.uniqid('', true);
        $role = Role::create([
            'name' => $unique,
            'description' => '',
            'permissions' => ['posts.view'],
            'menu_access' => ['main-dashboard'],
        ]);

        $response = $this->actingAs($user)->putJson('/api/roles/'.$role->id, [
            'name' => $unique,
            'description' => '',
            'permissions' => ['posts.view', 'pages.view'],
            'menuAccess' => ['main-dashboard', 'kerisi-chat'],
        ]);

        $response->assertOk();
        $role->refresh();
        $this->assertSame(['posts.view', 'pages.view'], $role->permissions);
        $this->assertSame(['main-dashboard', 'kerisi-chat'], $role->menu_access);
    }
}
