<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeInternalTicketSyncTest extends TestCase
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

    public function test_guest_cannot_access_internal_ticket_sync_logs(): void
    {
        $this->getJson('/api/knowledge/internal-ticket-sync-logs')->assertUnauthorized();
    }

    public function test_user_without_ticket_or_knowledge_access_cannot_view_internal_sync_logs(): void
    {
        $user = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $this->attachPermissions($user, ['posts.view']);

        $this->actingAs($user)->getJson('/api/knowledge/internal-ticket-sync-logs')->assertForbidden();
    }

    public function test_staff_with_tickets_view_can_list_internal_sync_logs(): void
    {
        $user = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $this->attachPermissions($user, ['tickets.view']);

        $this->actingAs($user)->getJson('/api/knowledge/internal-ticket-sync-logs')->assertOk();
    }

    public function test_internal_tickets_preview_requires_knowledge_manage(): void
    {
        $user = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $this->attachPermissions($user, ['tickets.view']);

        $this->actingAs($user)->getJson('/api/knowledge/internal-tickets-preview')->assertForbidden();
    }

    public function test_internal_tickets_preview_returns_latest_tickets(): void
    {
        $admin = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $this->attachPermissions($admin, ['knowledge.manage', 'tickets.view']);

        $requestor = User::factory()->create(['user_level' => UserLevel::USER]);
        SupportTicket::create([
            'ticket_number' => 'TKT-INT-PREV-1',
            'subject' => 'Ujian pratonton',
            'description' => 'Isi',
            'priority' => 'normal',
            'status' => 'new',
            'module' => 'GL',
            'created_by_user_id' => $requestor->id,
        ]);

        $res = $this->actingAs($admin)->getJson('/api/knowledge/internal-tickets-preview?limit=10');
        $res->assertOk();
        $data = $res->json('data');
        $this->assertNotEmpty($data);
        $this->assertSame('TKT-INT-PREV-1', $data[0]['ticketNumber'] ?? null);
    }
}
