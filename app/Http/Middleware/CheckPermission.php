<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to perform this action.',
                ],
            ], 403);
        }

        $user->loadMissing('roles');

        if (! $user->hasPermission($permission)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to perform this action.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
