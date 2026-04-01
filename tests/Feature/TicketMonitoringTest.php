<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketMonitoringTest extends TestCase
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

    public function test_guest_cannot_access_ticket_monitoring(): void
    {
        $this->getJson('/api/tickets/monitoring')->assertUnauthorized();
    }

    public function test_user_without_access_gets_forbidden(): void
    {
        $user = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $this->attachPermissions($user, ['posts.view']);

        $this->actingAs($user)->getJson('/api/tickets/monitoring')->assertForbidden();
    }

    public function test_tickets_view_can_load_monitoring_payload(): void
    {
        $user = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $this->attachPermissions($user, ['tickets.view']);

        $requestor = User::factory()->create(['user_level' => UserLevel::USER]);
        SupportTicket::create([
            'ticket_number' => 'TKT-MON-1',
            'subject' => 'Test',
            'description' => 'D',
            'priority' => 'normal',
            'status' => 'new',
            'module' => 'GL',
            'created_by_user_id' => $requestor->id,
        ]);

        $res = $this->actingAs($user)->getJson('/api/tickets/monitoring');
        $res->assertOk();
        $res->assertJsonStructure([
            'data' => [
                'internal' => ['total', 'open', 'unassigned', 'byStatus', 'byPriority', 'byModule', 'openByAssignee', 'createdLast7Days', 'closedLast7Days'],
                'desk365Synced' => ['total', 'byStatus', 'byModule', 'byPriority', 'openByAgent'],
                'chatActivity' => ['sessionsByUser'],
                'aiKnowledge' => ['desk365DocumentCount', 'desk365UploadedCount', 'internalDocumentCount', 'internalUploadedCount'],
                'lastSync' => ['desk365', 'internal'],
                'generatedAt',
            ],
        ]);
    }
}
