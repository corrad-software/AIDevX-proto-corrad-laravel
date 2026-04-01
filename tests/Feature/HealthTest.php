<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_returns_ok_when_database_and_migrations_ready(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('database', 'connected')
            ->assertJsonPath('migrationsTable', true);

        $this->assertIsInt($response->json('migrationsCount'));
    }
}
