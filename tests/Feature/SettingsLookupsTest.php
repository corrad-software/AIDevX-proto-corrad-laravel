<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsLookupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookups_returns_system_and_user_level_defaults(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/settings/lookups');

        $response->assertOk()
            ->assertJsonPath('data.system.0', 'KERISI');

        $rows = $response->json('data.userLevel');
        $this->assertIsArray($rows);
        $this->assertCount(6, $rows);
        $this->assertSame('0', $rows[0]['code']);
        $this->assertSame('developer', $rows[0]['desc']);
        $this->assertSame('1', $rows[1]['code']);
        $this->assertSame('admin internal', $rows[1]['desc']);
        $this->assertSame('4', $rows[4]['code']);
        $this->assertSame('user', $rows[4]['desc']);
        $this->assertSame('5', $rows[5]['code']);
        $this->assertSame('secondary user', $rows[5]['desc']);

        $cat = $response->json('data.userCategory');
        $this->assertIsArray($cat);
        $this->assertCount(2, $cat);
        $this->assertSame('tempatan', $cat[0]['code']);
        $this->assertSame('user tempatan', $cat[0]['desc']);
        $this->assertSame('luar_negara', $cat[1]['code']);
        $this->assertSame('luar negara', $cat[1]['desc']);

        $seg = $response->json('data.userSegment');
        $this->assertIsArray($seg);
        $this->assertCount(2, $seg);
        $this->assertSame('1', $seg[0]['code']);
        $this->assertSame('Government', $seg[0]['desc']);
        $this->assertSame('2', $seg[1]['code']);
        $this->assertSame('Private', $seg[1]['desc']);

        $jp = $response->json('data.userJenisPengguna');
        $this->assertIsArray($jp);
        $this->assertCount(2, $jp);
        $this->assertSame('1', $jp[0]['code']);
        $this->assertSame('Tempatan', $jp[0]['desc']);
        $this->assertSame('2', $jp[1]['code']);
        $this->assertSame('Luar negara', $jp[1]['desc']);
    }

    public function test_guest_cannot_get_lookups(): void
    {
        $this->getJson('/api/settings/lookups')->assertUnauthorized();
    }

    public function test_update_lookups_persists_user_level_rows(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/settings/lookups', [
            'system' => ['KERISI'],
            'userLevel' => [
                ['code' => '0', 'desc' => 'dev'],
                ['code' => '1', 'desc' => 'internal'],
            ],
        ])->assertOk();

        $get = $this->actingAs($user)->getJson('/api/settings/lookups');
        $get->assertOk();
        $this->assertSame(['KERISI'], $get->json('data.system'));
        $this->assertSame(
            [
                ['code' => '0', 'desc' => 'dev'],
                ['code' => '1', 'desc' => 'internal'],
            ],
            $get->json('data.userLevel')
        );
        $cat = $get->json('data.userCategory');
        $this->assertIsArray($cat);
        $this->assertCount(2, $cat);
        $this->assertSame('tempatan', $cat[0]['code']);

        $seg = $get->json('data.userSegment');
        $this->assertIsArray($seg);
        $this->assertCount(2, $seg);
        $this->assertSame('1', $seg[0]['code']);
    }

    public function test_update_lookups_persists_user_category_rows(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/settings/lookups', [
            'system' => ['KERISI'],
            'userCategory' => [
                ['code' => 'a', 'desc' => 'Alpha'],
                ['code' => 'b', 'desc' => 'Beta'],
            ],
        ])->assertOk();

        $get = $this->actingAs($user)->getJson('/api/settings/lookups');
        $get->assertOk();
        $this->assertSame(
            [
                ['code' => 'a', 'desc' => 'Alpha'],
                ['code' => 'b', 'desc' => 'Beta'],
            ],
            $get->json('data.userCategory')
        );

        $seg = $get->json('data.userSegment');
        $this->assertIsArray($seg);
        $this->assertCount(2, $seg);
        $this->assertSame('Government', $seg[0]['desc']);
    }

    public function test_update_lookups_persists_user_segment_rows(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/settings/lookups', [
            'system' => ['KERISI'],
            'userSegment' => [
                ['code' => '1', 'desc' => 'Public sector'],
                ['code' => '2', 'desc' => 'Commercial'],
            ],
        ])->assertOk();

        $get = $this->actingAs($user)->getJson('/api/settings/lookups');
        $get->assertOk();
        $this->assertSame(
            [
                ['code' => '1', 'desc' => 'Public sector'],
                ['code' => '2', 'desc' => 'Commercial'],
            ],
            $get->json('data.userSegment')
        );
    }

    public function test_update_lookups_persists_user_jenis_pengguna_rows(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/settings/lookups', [
            'system' => ['KERISI'],
            'userJenisPengguna' => [
                ['code' => '1', 'desc' => 'Domestik'],
                ['code' => '2', 'desc' => 'Antarabangsa'],
            ],
        ])->assertOk();

        $get = $this->actingAs($user)->getJson('/api/settings/lookups');
        $get->assertOk();
        $this->assertSame(
            [
                ['code' => '1', 'desc' => 'Domestik'],
                ['code' => '2', 'desc' => 'Antarabangsa'],
            ],
            $get->json('data.userJenisPengguna')
        );
    }
}
