<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmailVerificationService
{
    public function createToken(string $email, int $expiresMinutes = 60): string
    {
        $token = Str::random(64);
        DB::table('email_verification_tokens')->insert([
            'email' => $email,
            'token' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($expiresMinutes),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    public function verifyToken(string $token): ?string
    {
        $hashed = hash('sha256', $token);
        $row = DB::table('email_verification_tokens')
            ->where('token', $hashed)
            ->where('expires_at', '>', now())
            ->first();

        if (! $row) {
            return null;
        }

        DB::table('email_verification_tokens')->where('token', $hashed)->delete();

        return $row->email;
    }

    public function invalidateForEmail(string $email): void
    {
        DB::table('email_verification_tokens')->where('email', $email)->delete();
    }
}
