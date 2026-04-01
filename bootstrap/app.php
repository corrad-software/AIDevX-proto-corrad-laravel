<?php

use App\Http\Middleware\CamelCaseMiddleware;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureFrontendRequestsAreStateful;
use App\Http\Middleware\EnsureSupportChatAccess;
use App\Http\Middleware\EnsureUserChatAccess;
use App\Http\Middleware\ImpersonateMiddleware;
use App\Http\Middleware\KerisiEmbedFrameAncestors;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->booting(function () {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinutes(10, 20)->by($request->ip());
        });
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->replaceInGroup(
            'api',
            Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            EnsureFrontendRequestsAreStateful::class,
        );

        $middleware->appendToGroup('web', [
            KerisiEmbedFrameAncestors::class,
        ]);

        $middleware->alias([
            'permission' => CheckPermission::class,
            'support_chat_access' => EnsureSupportChatAccess::class,
            'user_chat_access' => EnsureUserChatAccess::class,
            'impersonate' => ImpersonateMiddleware::class,
        ]);

        $middleware->appendToGroup('api', [
            CamelCaseMiddleware::class,
            ImpersonateMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => $e->getMessage(),
                        'details' => $e->errors(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => 'Resource not found.',
                    ],
                ], 404);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'UNAUTHORIZED',
                        'message' => 'Unauthenticated.',
                    ],
                ], 401);
            }
        });

        $exceptions->render(function (RouteNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'UNAUTHORIZED',
                        'message' => 'Unauthenticated.',
                    ],
                ], 401);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => 'The requested resource was not found.',
                    ],
                ], 404);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'TOO_MANY_REQUESTS',
                        'message' => 'Too many requests. Please try again later.',
                    ],
                ], 429);
            }
        });

        $exceptions->render(function (QueryException $e, Request $request) {
            if (($request->is('api/*') || $request->expectsJson()) && ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Integrity constraint'))) {
                return response()->json([
                    'error' => [
                        'code' => 'CONSTRAINT_VIOLATION',
                        'message' => 'Invalid or duplicate data. Please check your input and try again.',
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $isProduction = app()->environment('production');

                return response()->json([
                    'error' => [
                        'code' => 'INTERNAL_ERROR',
                        'message' => $isProduction ? 'An unexpected error occurred.' : $e->getMessage(),
                    ],
                ], 500);
            }
        });
    })->create();
