<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerCustomerAgentAssignmentTest extends TestCase
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

    public function test_agent_picklist_filters_by_customer_id(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $c1 = Customer::create(['customer_code' => 'C1', 'customer_name' => 'One', 'is_active' => true]);
        $c2 = Customer::create(['customer_code' => 'C2', 'customer_name' => 'Two', 'is_active' => true]);
        $a1 = User::factory()->create(['user_level' => UserLevel::AGENT]);
        $a2 = User::factory()->create(['user_level' => UserLevel::AGENT]);
        $a1->customers()->sync([$c1->id]);
        $a2->customers()->sync([$c2->id]);
        $this->attachPermissions($super, ['users.view', 'users.edit']);

        $res = $this->actingAs($super)->getJson('/api/users/agent-picklist?customer_id='.$c1->id);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($a1->id, $ids);
        $this->assertNotContains($a2->id, $ids);
    }

    public function test_super_admin_can_save_customer_agent_assignments_for_internal_admin(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $c1 = Customer::create(['customer_code' => 'MA', 'customer_name' => 'MAIPs', 'is_active' => true]);
        $l1->customers()->sync([$c1->id]);

        $agent = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l1->id]);
        $agent->customers()->sync([$c1->id]);

        $this->attachPermissions($super, ['users.view', 'users.edit']);

        $res = $this->actingAs($super)->putJson('/api/users/'.$l1->id, [
            'customer_ids' => [$c1->id],
            'managed_agent_ids' => [$agent->id],
            'customer_agent_assignments' => [
                ['customer_id' => $c1->id, 'agent_ids' => [$agent->id]],
            ],
        ]);
        $res->assertOk();
        $this->assertDatabaseHas('manager_customer_agents', [
            'manager_user_id' => $l1->id,
            'customer_id' => $c1->id,
            'agent_user_id' => $agent->id,
        ]);
        // Respons API dituturkan ke camelCase oleh CamelCaseMiddleware.
        $assignments = $res->json('data.customerAgentAssignments');
        $this->assertIsArray($assignments);
        $this->assertNotEmpty($assignments);
    }

    public function test_rejects_agent_not_reporting_to_manager(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $other = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $c1 = Customer::create(['customer_code' => 'X1', 'customer_name' => 'X', 'is_active' => true]);
        $l1->customers()->sync([$c1->id]);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $other->id]);
        $agent->customers()->sync([$c1->id]);
        $this->attachPermissions($super, ['users.view', 'users.edit']);

        // Jangan masukkan ejen dalam managed_agent_ids — ejen masih melapor kepada pentadbir lain.
        $res = $this->actingAs($super)->putJson('/api/users/'.$l1->id, [
            'customer_ids' => [$c1->id],
            'managed_agent_ids' => [],
            'customer_agent_assignments' => [
                ['customer_id' => $c1->id, 'agent_ids' => [$agent->id]],
            ],
        ]);
        $res->assertStatus(422);
    }

    public function test_rejects_agent_without_shared_customer(): void
    {
        $super = User::factory()->create(['user_level' => UserLevel::SUPER_ADMIN]);
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $c1 = Customer::create(['customer_code' => 'Y1', 'customer_name' => 'Y', 'is_active' => true]);
        $c2 = Customer::create(['customer_code' => 'Y2', 'customer_name' => 'Z', 'is_active' => true]);
        $l1->customers()->sync([$c1->id]);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l1->id]);
        $agent->customers()->sync([$c2->id]);
        $this->attachPermissions($super, ['users.view', 'users.edit']);

        $res = $this->actingAs($super)->putJson('/api/users/'.$l1->id, [
            'customer_ids' => [$c1->id],
            'managed_agent_ids' => [$agent->id],
            'customer_agent_assignments' => [
                ['customer_id' => $c1->id, 'agent_ids' => [$agent->id]],
            ],
        ]);
        $res->assertStatus(422);
    }
}
