<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPostsByYearTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_includes_posts_published_count_by_year(): void
    {
        $user = User::factory()->create();

        Post::create([
            'title' => 'A',
            'slug' => 'post-a-'.uniqid(),
            'content' => '<p>x</p>',
            'status' => 'published',
            'published_at' => '2023-06-15 10:00:00',
        ]);
        Post::create([
            'title' => 'B',
            'slug' => 'post-b-'.uniqid(),
            'content' => '<p>x</p>',
            'status' => 'published',
            'published_at' => '2023-08-01 10:00:00',
        ]);
        Post::create([
            'title' => 'C',
            'slug' => 'post-c-'.uniqid(),
            'content' => '<p>x</p>',
            'status' => 'published',
            'published_at' => '2024-01-01 10:00:00',
        ]);
        Post::create([
            'title' => 'Draft',
            'slug' => 'post-d-'.uniqid(),
            'content' => '<p>x</p>',
            'status' => 'draft',
            'published_at' => null,
        ]);

        $response = $this->actingAs($user)->getJson('/api/dashboard/summary');

        $response->assertOk();
        $rows = $response->json('data.postsByYear');
        $this->assertIsArray($rows);
        $byYear = collect($rows)->keyBy('year');
        $this->assertSame(2, (int) $byYear->get(2023)['count']);
        $this->assertSame(1, (int) $byYear->get(2024)['count']);
    }
}
