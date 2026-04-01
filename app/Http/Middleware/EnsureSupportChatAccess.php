<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Enums\UserLevel;
use Closure;
use Illuminate\Http\Request;

class EnsureSupportChatAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Authentication required.',
                ],
            ], 401);
        }

        // Allow if user_level is L0–L3 staff, OR if user has chat.use permission (role-based)
        $level = UserLevel::normalize($user->user_level ?? UserLevel::USER);
        $hasPermission = $user->hasPermission(Permission::CHAT_USE);
        if (! UserLevel::canAccessSupportChat($level) && ! $hasPermission) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Support Chat is for agents and administrators only. End users should use User Chat.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
