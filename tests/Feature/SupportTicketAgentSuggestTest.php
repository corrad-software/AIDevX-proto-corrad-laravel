<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportTicketAgentSuggestTest extends TestCase
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

    public function test_level4_cannot_request_agent_suggest(): void
    {
        $user = User::factory()->create(['user_level' => UserLevel::USER]);
        $this->attachPermissions($user, ['tickets.view', 'tickets.create', 'tickets.respond']);

        $agent = User::factory()->create(['user_level' => UserLevel::AGENT]);
        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-SUG-000001',
            'subject' => 'Help',
            'description' => 'Need assistance',
            'priority' => 'normal',
            'status' => 'assigned',
            'created_by_user_id' => $user->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $res = $this->actingAs($user)->postJson('/api/tickets/'.$ticket->id.'/agent-reply-suggest', []);
        $res->assertStatus(403);
    }

    public function test_unassigned_ticket_returns_400(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $requestor = User::factory()->create(['user_level' => UserLevel::USER]);
        $this->attachPermissions($l1, ['tickets.view', 'tickets.respond']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-SUG-000002',
            'subject' => 'Help',
            'description' => 'Body',
            'priority' => 'normal',
            'status' => 'new',
            'created_by_user_id' => $requestor->id,
            'assigned_to_user_id' => null,
        ]);

        Config::set('services.openai.key', 'sk-test');
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'x']]]], 200),
        ]);

        $res = $this->actingAs($l1)->postJson('/api/tickets/'.$ticket->id.'/agent-reply-suggest', []);
        $res->assertStatus(400);
        $this->assertStringContainsString('assigned', strtolower($res->json('error.message')));
    }

    public function test_staff_assigned_ticket_returns_suggestion(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $requestor = User::factory()->create(['user_level' => UserLevel::USER]);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT]);
        $this->attachPermissions($l1, ['tickets.view', 'tickets.respond']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-SUG-000003',
            'subject' => 'Printer issue',
            'description' => 'Cannot print',
            'priority' => 'normal',
            'status' => 'assigned',
            'created_by_user_id' => $requestor->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        Config::set('services.openai.key', 'sk-test');
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Terima kasih. Kami sedang semak pencetak anda.']],
                ],
            ], 200),
        ]);

        $res = $this->actingAs($l1)->postJson('/api/tickets/'.$ticket->id.'/agent-reply-suggest', [
            'regenerate_prompt' => 'Lebih ringkas',
        ]);
        $res->assertOk();
        $this->assertSame('Terima kasih. Kami sedang semak pencetak anda.', $res->json('data.suggestion'));
    }

    public function test_validation_rejects_long_regenerate_prompt(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $agent = User::factory()->create(['user_level' => UserLevel::AGENT]);
        $requestor = User::factory()->create(['user_level' => UserLevel::USER]);
        $this->attachPermissions($l1, ['tickets.view', 'tickets.respond']);

        $ticket = SupportTicket::create([
            'ticket_number' => 'TKT-SUG-000004',
            'subject' => 'X',
            'description' => 'Y',
            'priority' => 'normal',
            'status' => 'assigned',
            'created_by_user_id' => $requestor->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $res = $this->actingAs($l1)->postJson('/api/tickets/'.$ticket->id.'/agent-reply-suggest', [
            'regenerate_prompt' => str_repeat('a', 2001),
        ]);
        $res->assertStatus(422);
    }
}
