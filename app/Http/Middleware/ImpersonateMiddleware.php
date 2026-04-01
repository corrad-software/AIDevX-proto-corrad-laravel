<?php

namespace App\Http\Middleware;

use App\Enums\UserLevel;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ImpersonateMiddleware
{
    /**
     * Swap to impersonated user for this request. Skip for impersonation endpoints.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->path();
        if (in_array($path, ['api/auth/impersonate', 'api/auth/stop-impersonate', 'api/auth/impersonate-users'], true)) {
            return $next($request);
        }

        $impersonateId = session('impersonate_user_id');
        if (! $impersonateId) {
            return $next($request);
        }

        // Stale session: impersonation flags without an authenticated user → would crash on $realUser->user_level.
        if (! Auth::check()) {
            session()->forget(['impersonate_user_id', 'impersonated_by']);

            return $next($request);
        }

        $realUser = Auth::user();
        $level = UserLevel::normalize($realUser->user_level ?? '');
        $canImpersonate = UserLevel::canImpersonate($level);
        if (! $realUser || ! $canImpersonate) {
            session()->forget(['impersonate_user_id', 'impersonated_by']);

            return $next($request);
        }

        $impersonated = User::find($impersonateId);
        if (! $impersonated || ! $impersonated->is_active) {
            session()->forget(['impersonate_user_id', 'impersonated_by']);

            return $next($request);
        }

        Auth::setUser($impersonated);

        return $next($request);
    }
}
