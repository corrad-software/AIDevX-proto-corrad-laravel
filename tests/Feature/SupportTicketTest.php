<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketTest extends TestCase
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

    public function test_level4_user_can_create_and_list_own_ticket(): void
    {
        $user = User::factory()->create(['user_level' => UserLevel::USER]);
        $this->attachPermissions($user, ['tickets.view', 'tickets.create', 'tickets.edit', 'tickets.delete', 'tickets.respond']);

        $create = $this->actingAs($user)->postJson('/api/tickets', [
            'subject' => 'Cannot print receipt',
            'description' => 'Printer button does nothing',
            'module' => 'Cashbook',
            'priority' => 'normal',
        ]);
        $create->assertStatus(201);

        $list = $this->actingAs($user)->getJson('/api/tickets?page=1&limit=20');
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
    }

    public function test_level1_can_assign_ticket_to_agent_in_branch(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $requestor = User::factory()->create(['user_level' => UserLevel::USER, 'managed_by_user_id' => $l1->id]);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l1->id]);
        $this->attachPermissions($l1, ['tickets.view', 'tickets.assign', 'tickets.respond']);
        $this->attachPermissions($requestor, ['tickets.view', 'tickets.create', 'tickets.respond']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-TEST-000001',
            'subject' => 'Need help',
            'description' => 'Support required',
            'priority' => 'normal',
            'status' => 'new',
            'created_by_user_id' => $requestor->id,
        ]);

        $assign = $this->actingAs($l1)->postJson('/api/tickets/'.$ticket->id.'/assign', [
            'assigned_to_user_id' => $agent->id,
            'note' => 'Please assist',
        ]);
        $assign->assertOk();
        $ticket->refresh();
        $this->assertSame($agent->id, $ticket->assigned_to_user_id);
        $this->assertSame('assigned', $ticket->status);
    }

    public function test_requestor_cannot_delete_ticket_after_assignment(): void
    {
        $user = User::factory()->create(['user_level' => UserLevel::USER]);
        $this->attachPermissions($user, ['tickets.view', 'tickets.create', 'tickets.delete']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-TEST-000002',
            'subject' => 'Need help',
            'description' => 'Support required',
            'priority' => 'normal',
            'status' => 'assigned',
            'created_by_user_id' => $user->id,
        ]);

        $res = $this->actingAs($user)->deleteJson('/api/tickets/'.$ticket->id);
        $res->assertStatus(409);
    }

    public function test_external_admin_cannot_assign_agent_outside_hierarchy(): void
    {
        $l2 = User::factory()->create(['user_level' => UserLevel::EXTERNAL_ADMIN]);
        $requestor = User::factory()->create(['user_level' => UserLevel::USER, 'managed_by_user_id' => $l2->id]);
        $agentOutside = User::factory()->create(['user_level' => UserLevel::AGENT]);
        $this->attachPermissions($l2, ['tickets.view', 'tickets.assign', 'tickets.respond']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-TEST-000003',
            'subject' => 'Need assist',
            'description' => 'Need assistance',
            'priority' => 'normal',
            'status' => 'new',
            'created_by_user_id' => $requestor->id,
        ]);

        $assign = $this->actingAs($l2)->postJson('/api/tickets/'.$ticket->id.'/assign', [
            'assigned_to_user_id' => $agentOutside->id,
        ]);
        $assign->assertStatus(403);
    }
}
