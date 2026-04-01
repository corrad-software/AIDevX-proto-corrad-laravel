<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserHierarchyScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_admin_sees_only_own_downline(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $l2a = User::factory()->create(['user_level' => UserLevel::EXTERNAL_ADMIN, 'managed_by_user_id' => $l1->id]);
        $agentA = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l2a->id]);
        $userA = User::factory()->create(['user_level' => UserLevel::USER, 'managed_by_user_id' => $agentA->id]);

        $l2b = User::factory()->create(['user_level' => UserLevel::EXTERNAL_ADMIN, 'managed_by_user_id' => $l1->id]);
        $agentB = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l2b->id]);

        $response = $this->actingAs($l2a)->getJson('/api/users?page=1&limit=50');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($l2a->id, $ids);
        $this->assertContains($agentA->id, $ids);
        $this->assertContains($userA->id, $ids);
        $this->assertNotContains($l2b->id, $ids);
        $this->assertNotContains($agentB->id, $ids);
    }

    public function test_internal_admin_sees_level_2_3_4_under_hierarchy(): void
    {
        $l1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $l2 = User::factory()->create(['user_level' => UserLevel::EXTERNAL_ADMIN, 'managed_by_user_id' => $l1->id]);
        $agentFromL2 = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l2->id]);
        $userFromAgent = User::factory()->create(['user_level' => UserLevel::USER, 'managed_by_user_id' => $agentFromL2->id]);
        $directAgent = User::factory()->create(['user_level' => UserLevel::AGENT, 'managed_by_user_id' => $l1->id]);
        $directUser = User::factory()->create(['user_level' => UserLevel::USER, 'managed_by_user_id' => $l1->id]);

        $otherL1 = User::factory()->create(['user_level' => UserLevel::INTERNAL_ADMIN]);
        $otherL2 = User::factory()->create(['user_level' => UserLevel::EXTERNAL_ADMIN, 'managed_by_user_id' => $otherL1->id]);

        $response = $this->actingAs($l1)->getJson('/api/users?page=1&limit=50');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($l2->id, $ids);
        $this->assertContains($agentFromL2->id, $ids);
        $this->assertContains($userFromAgent->id, $ids);
        $this->assertContains($directAgent->id, $ids);
        $this->assertContains($directUser->id, $ids);
        $this->assertNotContains($otherL2->id, $ids);
    }
}
