<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserNotesTest extends TestCase
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

    public function test_super_admin_can_set_notes_on_update(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $target = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $this->attachPermissions($super, ['users.view', 'users.edit']);

        $res = $this->actingAs($super)->putJson('/api/users/'.$target->id, [
            'notes' => 'Catatan ujian pentadbir',
        ]);
        $res->assertOk();
        $target->refresh();
        $this->assertSame('Catatan ujian pentadbir', $target->notes);
    }

    public function test_create_user_persists_notes(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $this->attachPermissions($super, ['users.view', 'users.create']);

        $res = $this->actingAs($super)->postJson('/api/users', [
            'name' => 'Noted User',
            'email' => 'noted@example.com',
            'password' => 'password123',
            'user_level' => UserLevel::USER,
            'notes' => 'Dari create',
        ]);
        $res->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'noted@example.com',
            'notes' => 'Dari create',
        ]);
    }
}
