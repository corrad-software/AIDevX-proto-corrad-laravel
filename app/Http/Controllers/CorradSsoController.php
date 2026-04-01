<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Trusted handoff from CORRAD (vanilla PHP) into KAIS Laravel session.
 *
 * CORRAD builds a signed URL; this endpoint verifies HMAC, prevents replay, logs the user in.
 */
class CorradSsoController extends Controller
{
    private const ALLOWED_REDIRECT_PATHS = [
        '/admin/kerisi/user-chat',
    ];

    public function __construct(
        protected AuditService $auditService,
    ) {}

    public function login(Request $request): RedirectResponse
    {
        $secret = config('corrad.sso_secret');
        if (! is_string($secret) || $secret === '') {
            abort(404);
        }

        $email = strtolower(trim((string) $request->query('email', '')));
        $ts = (int) $request->query('ts', 0);
        $nonce = trim((string) $request->query('nonce', ''));
        $sig = trim((string) $request->query('sig', ''));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $ts <= 0 || $nonce === '' || $sig === '') {
            abort(403, 'Invalid SSO request.');
        }

        $maxAge = max(30, min(600, (int) config('corrad.sso_max_age_seconds', 120)));
        if (abs(time() - $ts) > $maxAge) {
            abort(403, 'SSO link expired.');
        }

        $payload = $email.'|'.$ts.'|'.$nonce;
        $expected = hash_hmac('sha256', $payload, $secret);
        if (! hash_equals($expected, $sig)) {
            abort(403, 'Invalid SSO signature.');
        }

        $cacheKey = 'corrad_sso_nonce:'.hash('sha256', $nonce);
        if (! Cache::add($cacheKey, 1, now()->addSeconds($maxAge + 60))) {
            abort(403, 'SSO nonce already used.');
        }

        $user = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->first();

        if (! $user) {
            abort(403, 'No matching KAIS user for this email. Provision the user in KAIS with the same email as CORRAD.');
        }

        Auth::login($user, false);
        $request->session()->regenerate();

        $this->auditService->logAuth('corrad_sso_login', $user);

        $redirectTo = (string) $request->query('redirect', '/admin/kerisi/user-chat');
        if (! in_array($redirectTo, self::ALLOWED_REDIRECT_PATHS, true)) {
            $redirectTo = '/admin/kerisi/user-chat';
        }

        return redirect()->to($redirectTo);
    }
}
