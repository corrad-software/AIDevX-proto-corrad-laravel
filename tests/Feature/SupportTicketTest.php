<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\InAppNotification;
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

    public function test_level3_agent_can_create_ticket(): void
    {
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT]);
        $this->attachPermissions($agent, ['tickets.view', 'tickets.create', 'tickets.edit', 'tickets.respond']);

        $create = $this->actingAs($agent)->postJson('/api/tickets', [
            'subject' => 'Agent raised issue',
            'description' => 'Details from agent',
            'module' => 'GL',
            'priority' => 'normal',
        ]);
        $create->assertStatus(201);
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

    public function test_external_admin_can_assign_ticket_to_agent_they_manage(): void
    {
        $l2 = User::factory()->create(['user_level' => UserLevel::EXTERNAL_ADMIN]);
        $requestor = User::factory()->create(['user_level' => UserLevel::USER, 'managed_by_user_id' => $l2->id]);
        $agentDirect = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l2->id]);
        $this->attachPermissions($l2, ['tickets.view', 'tickets.assign', 'tickets.respond']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-TEST-L2-ASSIGN-OK',
            'subject' => 'Need assist',
            'description' => 'Need assistance',
            'priority' => 'normal',
            'status' => 'new',
            'created_by_user_id' => $requestor->id,
        ]);

        $assign = $this->actingAs($l2)->postJson('/api/tickets/'.$ticket->id.'/assign', [
            'assigned_to_user_id' => $agentDirect->id,
        ]);
        $assign->assertOk();
        $ticket->refresh();
        $this->assertSame($agentDirect->id, $ticket->assigned_to_user_id);
    }

    public function test_level4_requestor_cannot_assign_ticket(): void
    {
        $user = User::factory()->create(['user_level' => UserLevel::USER, 'managed_by_user_id' => null]);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT]);
        $this->attachPermissions($user, ['tickets.view', 'tickets.create', 'tickets.edit', 'tickets.assign', 'tickets.respond']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-TEST-L4-NO-ASSIGN',
            'subject' => 'Help',
            'description' => 'Body',
            'priority' => 'normal',
            'status' => 'new',
            'created_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)->postJson('/api/tickets/'.$ticket->id.'/assign', [
            'assigned_to_user_id' => $agent->id,
        ])->assertStatus(403);
    }

    public function test_level4_cannot_update_ticket_after_assigned(): void
    {
        $user = User::factory()->create(['user_level' => UserLevel::USER]);
        $this->attachPermissions($user, ['tickets.view', 'tickets.create', 'tickets.edit']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-TEST-L4-LOCK',
            'subject' => 'Original',
            'description' => 'Body',
            'priority' => 'normal',
            'status' => 'assigned',
            'created_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)->patchJson('/api/tickets/'.$ticket->id, [
            'subject' => 'Changed',
        ])->assertStatus(409);
    }

    public function test_agent_cannot_change_ticket_subject_via_patch(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l1->id]);
        $requestor = User::factory()->create(['user_level' => UserLevel::USER, 'managed_by_user_id' => $l1->id]);
        $this->attachPermissions($agent, ['tickets.view', 'tickets.edit', 'tickets.respond']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-TEST-AGENT-NO-EDIT',
            'subject' => 'Original subject',
            'description' => 'Body',
            'priority' => 'normal',
            'status' => 'in_progress',
            'created_by_user_id' => $requestor->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)->patchJson('/api/tickets/'.$ticket->id, [
            'subject' => 'Hacked',
        ])->assertOk();
        $ticket->refresh();
        $this->assertSame('Original subject', $ticket->subject);
    }

    public function test_level3_agent_can_assign_ticket_to_peer_agent_under_same_manager(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $agentA = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l1->id]);
        $agentB = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l1->id]);
        $this->attachPermissions($agentA, ['tickets.view', 'tickets.assign', 'tickets.respond']);
        $this->attachPermissions($agentB, ['tickets.view', 'tickets.respond']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-TEST-L3-ASSIGN',
            'subject' => 'Hand off',
            'description' => 'Please take over',
            'priority' => 'normal',
            'status' => 'new',
            'created_by_user_id' => $agentA->id,
        ]);

        $assign = $this->actingAs($agentA)->postJson('/api/tickets/'.$ticket->id.'/assign', [
            'assigned_to_user_id' => $agentB->id,
        ]);
        $assign->assertOk();
        $ticket->refresh();
        $this->assertSame($agentB->id, $ticket->assigned_to_user_id);
    }

    public function test_agent_retains_ticket_access_after_reassign_so_detail_does_not_404(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $agentA = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l1->id]);
        $agentB = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l1->id]);
        $requestor = User::factory()->create(['user_level' => UserLevel::USER, 'managed_by_user_id' => $l1->id]);
        $this->attachPermissions($agentA, ['tickets.view', 'tickets.assign', 'tickets.respond']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-TEST-REASSIGN-VIEW',
            'subject' => 'Shared',
            'description' => 'Body',
            'priority' => 'normal',
            'status' => 'assigned',
            'created_by_user_id' => $requestor->id,
            'assigned_to_user_id' => $agentA->id,
        ]);

        $this->actingAs($agentA)->postJson('/api/tickets/'.$ticket->id.'/assign', [
            'assigned_to_user_id' => $agentB->id,
        ])->assertOk();

        $detail = $this->actingAs($agentA)->getJson('/api/tickets/'.$ticket->id);
        $detail->assertOk();
        $messages = $detail->json('data.messages', []);
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('Alihan tugas', (string) ($messages[array_key_last($messages)]['message'] ?? ''));
    }

    public function test_agent_can_patch_ticket_status_to_closed(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l1->id]);
        $requestor = User::factory()->create(['user_level' => UserLevel::USER, 'managed_by_user_id' => $l1->id]);
        $this->attachPermissions($agent, ['tickets.view', 'tickets.edit', 'tickets.respond']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-TEST-PATCH-CLOSE',
            'subject' => 'Issue',
            'description' => 'Details',
            'priority' => 'normal',
            'status' => 'assigned',
            'created_by_user_id' => $requestor->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $res = $this->actingAs($agent)->patchJson('/api/tickets/'.$ticket->id, [
            'status' => 'closed',
        ]);
        $res->assertOk();
        $ticket->refresh();
        $this->assertSame('closed', $ticket->status);
        $this->assertSame($agent->id, $ticket->closed_by_user_id);
        $this->assertNotNull($ticket->closed_at);
    }

    public function test_agent_can_reply_with_status_resolved(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l1->id]);
        $requestor = User::factory()->create(['user_level' => UserLevel::USER, 'managed_by_user_id' => $l1->id]);
        $this->attachPermissions($agent, ['tickets.view', 'tickets.respond']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-TEST-REPLY-RES',
            'subject' => 'Issue',
            'description' => 'Details',
            'priority' => 'normal',
            'status' => 'assigned',
            'created_by_user_id' => $requestor->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $res = $this->actingAs($agent)->postJson('/api/tickets/'.$ticket->id.'/reply', [
            'message' => 'Fixed in release',
            'status' => 'resolved',
        ]);
        $res->assertOk();
        $ticket->refresh();
        $this->assertSame('resolved', $ticket->status);
    }

    public function test_reply_with_mention_notifies_other_agent(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $agentA = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l1->id]);
        $agentB = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l1->id]);
        $requestor = User::factory()->create(['user_level' => UserLevel::USER, 'managed_by_user_id' => $l1->id]);
        $this->attachPermissions($agentA, ['tickets.view', 'tickets.respond']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-TEST-MENTION',
            'subject' => 'Handoff',
            'description' => 'Need B',
            'priority' => 'normal',
            'status' => 'assigned',
            'created_by_user_id' => $requestor->id,
            'assigned_to_user_id' => $agentA->id,
        ]);

        $this->actingAs($agentA)->postJson('/api/tickets/'.$ticket->id.'/reply', [
            'message' => '@Agent B please check',
            'status' => 'in_progress',
            'mentioned_user_ids' => [$agentB->id],
        ])->assertOk();

        $n = InAppNotification::query()
            ->where('user_id', $agentB->id)
            ->where('event_key', 'ticket.mention')
            ->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString($agentA->name, (string) $n->body);
    }

    public function test_reply_rejects_mention_outside_ticket_scope(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $agentA = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l1->id]);
        $stranger = User::factory()->create(['user_level' => UserLevel::AGENT]);
        $requestor = User::factory()->create(['user_level' => UserLevel::USER, 'managed_by_user_id' => $l1->id]);
        $this->attachPermissions($agentA, ['tickets.view', 'tickets.respond']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-TEST-MENTION-BAD',
            'subject' => 'X',
            'description' => 'Y',
            'priority' => 'normal',
            'status' => 'assigned',
            'created_by_user_id' => $requestor->id,
            'assigned_to_user_id' => $agentA->id,
        ]);

        $this->actingAs($agentA)->postJson('/api/tickets/'.$ticket->id.'/reply', [
            'message' => 'Hi',
            'status' => 'in_progress',
            'mentioned_user_ids' => [$stranger->id],
        ])->assertStatus(422);
    }

    public function test_internal_admin_with_mixed_case_user_level_sees_all_tickets(): void
    {
        $l1 = User::factory()->create(['user_level' => 'INTERNAL_ADMIN']);
        $this->attachPermissions($l1, ['tickets.view']);

        $requestor = User::factory()->create(['user_level' => UserLevel::USER]);
        SupportTicket::create([
            'ticket_number' => 'TKT-TEST-MIXED-001',
            'subject' => 'From L4',
            'description' => 'Body',
            'priority' => 'normal',
            'status' => 'new',
            'created_by_user_id' => $requestor->id,
        ]);

        $list = $this->actingAs($l1)->getJson('/api/tickets?page=1&limit=20');
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, count($list->json('data')));
        $this->assertSame('TKT-TEST-MIXED-001', $list->json('data.0.ticketNumber'));
    }

    /** Regression: COALESCE(customer_name, "") broke MySQL ("" is identifier); must use ''. */
    public function test_ticket_list_search_by_customer_name_does_not_error(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $this->attachPermissions($l1, ['tickets.view']);

        SupportTicket::create([
            'ticket_number' => 'TKT-SEARCH-CUST',
            'subject' => 'Generic subject',
            'description' => 'Body',
            'customer_name' => 'UniqueCustomerXyz',
            'system_name' => 'SysA',
            'priority' => 'normal',
            'status' => 'new',
            'created_by_user_id' => $l1->id,
        ]);

        $list = $this->actingAs($l1)->getJson('/api/tickets?page=1&limit=20&q='.rawurlencode('UniqueCustomer'));
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, count($list->json('data')));
    }
}
