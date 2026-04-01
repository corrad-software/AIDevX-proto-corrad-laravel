<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserValidationTest extends TestCase
{
    use RefreshDatabase;

    private function attachPermissions(User $user, array $permissions): void
    {
        $role = Role::create([
            'name' => 'role-'.uniqid(),
            'description' => 't',
            'permissions' => $permissions,
            'menu_access' => null,
        ]);
        $user->roles()->sync([$role->id]);
    }

    public function test_store_user_requires_name(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $this->attachPermissions($super, ['users.create']);

        $res = $this->actingAs($super)->postJson('/api/users', [
            'email' => 'a@example.com',
            'password' => 'secret12',
            'user_level' => UserLevel::USER,
        ]);
        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $res->assertJsonStructure(['error' => ['details' => ['name']]]);
    }

    public function test_store_user_requires_valid_email(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $this->attachPermissions($super, ['users.create']);

        $res = $this->actingAs($super)->postJson('/api/users', [
            'name' => 'Test',
            'email' => 'not-an-email',
            'password' => 'secret12',
            'user_level' => UserLevel::USER,
        ]);
        $res->assertStatus(422);
        $res->assertJsonStructure(['error' => ['details' => ['email']]]);
    }

    public function test_store_user_password_min_length(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $this->attachPermissions($super, ['users.create']);

        $res = $this->actingAs($super)->postJson('/api/users', [
            'name' => 'Test',
            'email' => 'valid@example.com',
            'password' => '12345',
            'user_level' => UserLevel::USER,
        ]);
        $res->assertStatus(422);
        $res->assertJsonStructure(['error' => ['details' => ['password']]]);
    }

    public function test_store_user_rejects_invalid_user_level(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $this->attachPermissions($super, ['users.create']);

        $res = $this->actingAs($super)->postJson('/api/users', [
            'name' => 'Test',
            'email' => 'valid@example.com',
            'password' => 'secret12',
            'user_level' => 'not_a_real_level',
        ]);
        $res->assertStatus(422);
        $res->assertJsonStructure(['error' => ['details' => ['userLevel']]]);
    }

    public function test_store_user_notes_max_length(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $this->attachPermissions($super, ['users.create']);

        $res = $this->actingAs($super)->postJson('/api/users', [
            'name' => 'Test',
            'email' => 'valid@example.com',
            'password' => 'secret12',
            'user_level' => UserLevel::USER,
            'notes' => str_repeat('a', 10001),
        ]);
        $res->assertStatus(422);
        $res->assertJsonStructure(['error' => ['details' => ['notes']]]);
    }

    public function test_update_user_rejects_invalid_email(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $target = User::factory()->create();
        $this->attachPermissions($super, ['users.edit']);

        $res = $this->actingAs($super)->putJson('/api/users/'.$target->id, [
            'email' => 'invalid',
        ]);
        $res->assertStatus(422);
        $res->assertJsonStructure(['error' => ['details' => ['email']]]);
    }

    public function test_update_user_notes_max_length(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $target = User::factory()->create();
        $this->attachPermissions($super, ['users.edit']);

        $res = $this->actingAs($super)->putJson('/api/users/'.$target->id, [
            'notes' => str_repeat('x', 10001),
        ]);
        $res->assertStatus(422);
        $res->assertJsonStructure(['error' => ['details' => ['notes']]]);
    }

    public function test_update_user_password_min_length(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $target = User::factory()->create();
        $this->attachPermissions($super, ['users.edit']);

        $res = $this->actingAs($super)->putJson('/api/users/'.$target->id, [
            'password' => '12345',
        ]);
        $res->assertStatus(422);
        $res->assertJsonStructure(['error' => ['details' => ['password']]]);
    }
}
