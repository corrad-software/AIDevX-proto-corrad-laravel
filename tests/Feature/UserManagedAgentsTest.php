<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagedAgentsTest extends TestCase
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

    public function test_agent_picklist_returns_visible_agents(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT]);
        $this->attachPermissions($super, ['users.view', 'users.edit']);

        $res = $this->actingAs($super)->getJson('/api/users/agent-picklist');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($agent->id, $ids);
    }

    public function test_internal_admin_can_update_self_with_same_user_level_and_managed_agents(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN, 'name' => 'L1 Self']);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l1->id]);
        $this->attachPermissions($l1, ['users.view', 'users.edit']);

        $res = $this->actingAs($l1)->putJson('/api/users/'.$l1->id, [
            'user_level' => UserLevel::INTERNAL_ADMIN,
            'managed_agent_ids' => [$agent->id],
            'name' => 'L1 Self Updated',
        ]);
        $res->assertOk();
        $this->assertSame('L1 Self Updated', $res->json('data.name'));
        $agent->refresh();
        $this->assertSame($l1->id, $agent->managed_by_user_id);
    }

    public function test_agent_picklist_includes_legacy_l3_user_level_for_internal_admin(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $agentLegacy = User::factory()->create(['user_level' => 'l3']);
        $this->attachPermissions($l1, ['users.view', 'users.edit']);

        $res = $this->actingAs($l1)->getJson('/api/users/agent-picklist');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($agentLegacy->id, $ids);
    }

    public function test_agent_picklist_treats_spaced_internal_admin_db_value_as_level_1(): void
    {
        $l1 = User::factory()->create(['user_level' => 'internal admin']);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT]);
        $this->attachPermissions($l1, ['users.view', 'users.edit']);

        $res = $this->actingAs($l1)->getJson('/api/users/agent-picklist');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($agent->id, $ids);
    }

    public function test_agent_picklist_treats_numeric_string_1_as_internal_admin(): void
    {
        $l1 = User::factory()->create(['user_level' => '1']);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT]);
        $this->attachPermissions($l1, ['users.view', 'users.edit']);

        $res = $this->actingAs($l1)->getJson('/api/users/agent-picklist');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($agent->id, $ids);
    }

    public function test_super_admin_can_set_managed_agents_on_internal_admin_user(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => null]);
        $this->attachPermissions($super, ['users.view', 'users.edit']);

        $res = $this->actingAs($super)->putJson('/api/users/'.$l1->id, [
            'managed_agent_ids' => [$agent->id],
        ]);
        $res->assertOk();
        $agent->refresh();
        $this->assertSame($l1->id, $agent->managed_by_user_id);
    }

    public function test_super_admin_can_set_managed_agents_on_super_admin_user(): void
    {
        $actor = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $targetSuper = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => null]);
        $this->attachPermissions($actor, ['users.view', 'users.edit']);

        $res = $this->actingAs($actor)->putJson('/api/users/'.$targetSuper->id, [
            'managed_agent_ids' => [$agent->id],
        ]);
        $res->assertOk();
        $agent->refresh();
        $this->assertSame($targetSuper->id, $agent->managed_by_user_id);
        $this->assertDatabaseHas('user_managed_agents', [
            'manager_user_id' => $targetSuper->id,
            'agent_user_id' => $agent->id,
        ]);
    }

    public function test_super_admin_can_set_managed_agents_on_external_admin_user(): void
    {
        $actor = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $l2 = User::factory()->create(['user_level' => UserLevel::EXTERNAL_ADMIN]);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => null]);
        $this->attachPermissions($actor, ['users.view', 'users.edit']);

        $res = $this->actingAs($actor)->putJson('/api/users/'.$l2->id, [
            'managed_agent_ids' => [$agent->id],
        ]);
        $res->assertOk();
        $agent->refresh();
        $this->assertSame($l2->id, $agent->managed_by_user_id);
    }

    public function test_managed_agent_ids_rejected_for_level4_user(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $l4 = User::factory()->create(['user_level' => UserLevel::USER]);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT]);
        $this->attachPermissions($super, ['users.view', 'users.edit']);

        $res = $this->actingAs($super)->putJson('/api/users/'.$l4->id, [
            'managed_agent_ids' => [$agent->id],
        ]);
        $res->assertStatus(422);
    }

    public function test_super_admin_can_set_managed_agents_on_level3_agent_user(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $l3Manager = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => null]);
        $subAgent = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => null]);
        $this->attachPermissions($super, ['users.view', 'users.edit']);

        $res = $this->actingAs($super)->putJson('/api/users/'.$l3Manager->id, [
            'managed_agent_ids' => [$subAgent->id],
        ]);
        $res->assertOk();
        $subAgent->refresh();
        $this->assertSame($l3Manager->id, $subAgent->managed_by_user_id);
        $this->assertDatabaseHas('user_managed_agents', [
            'manager_user_id' => $l3Manager->id,
            'agent_user_id' => $subAgent->id,
        ]);
    }
}
