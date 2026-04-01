<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorradSsoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['corrad.sso_secret' => 'unit-test-corrad-secret']);
        config(['corrad.sso_max_age_seconds' => 120]);
    }

    public function test_sso_disabled_when_secret_empty(): void
    {
        config(['corrad.sso_secret' => '']);

        $this->get('/auth/corrad-sso?email=a@b.com&ts=1&nonce=x&sig=y')->assertNotFound();
    }

    public function test_valid_signature_logs_in_and_redirects(): void
    {
        $user = User::factory()->create([
            'email' => 'corrad.user@example.test',
            'is_active' => true,
        ]);

        $ts = time();
        $nonce = bin2hex(random_bytes(8));
        $email = 'corrad.user@example.test';
        $payload = $email.'|'.$ts.'|'.$nonce;
        $sig = hash_hmac('sha256', $payload, 'unit-test-corrad-secret');

        $response = $this->get('/auth/corrad-sso?'.http_build_query([
            'email' => $email,
            'ts' => $ts,
            'nonce' => $nonce,
            'sig' => $sig,
        ]));

        $response->assertRedirect('/admin/kerisi/user-chat');
        $this->assertAuthenticatedAs($user);
    }

    public function test_replay_nonce_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'replay@example.test',
            'is_active' => true,
        ]);

        $ts = time();
        $nonce = 'fixednonce123456';
        $email = 'replay@example.test';
        $payload = $email.'|'.$ts.'|'.$nonce;
        $sig = hash_hmac('sha256', $payload, 'unit-test-corrad-secret');

        $q = http_build_query([
            'email' => $email,
            'ts' => $ts,
            'nonce' => $nonce,
            'sig' => $sig,
        ]);

        $this->get('/auth/corrad-sso?'.$q)->assertRedirect('/admin/kerisi/user-chat');
        $this->get('/auth/corrad-sso?'.$q)->assertForbidden();
    }

    public function test_bad_signature_forbidden(): void
    {
        User::factory()->create([
            'email' => 'bad@example.test',
            'is_active' => true,
        ]);

        $ts = time();
        $nonce = 'n1';
        $response = $this->get('/auth/corrad-sso?'.http_build_query([
            'email' => 'bad@example.test',
            'ts' => $ts,
            'nonce' => $nonce,
            'sig' => 'deadbeef',
        ]));

        $response->assertForbidden();
        $this->assertGuest();
    }

    public function test_unknown_email_forbidden(): void
    {
        $ts = time();
        $nonce = 'n2';
        $email = 'missing@example.test';
        $payload = $email.'|'.$ts.'|'.$nonce;
        $sig = hash_hmac('sha256', $payload, 'unit-test-corrad-secret');

        $this->get('/auth/corrad-sso?'.http_build_query([
            'email' => $email,
            'ts' => $ts,
            'nonce' => $nonce,
            'sig' => $sig,
        ]))->assertForbidden();
    }
}
