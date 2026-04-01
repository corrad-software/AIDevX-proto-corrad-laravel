<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnostics for local/dev: DB connection + migrations table (Isu #3).
 * No auth — safe to call from browser or curl behind Vite proxy.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $data = [
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
        ];

        try {
            DB::connection()->getPdo();
            $data['database'] = 'connected';
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'database' => 'disconnected',
                'error' => $e->getMessage(),
                'hint' => 'Semak DB_CONNECTION / DB_DATABASE dalam .env dan pastikan pelayan DB jalan.',
            ], 503);
        }

        try {
            if (! Schema::hasTable('migrations')) {
                return response()->json([
                    'ok' => false,
                    'database' => 'connected',
                    'migrations' => 'missing',
                    'hint' => 'Jalankan: php artisan migrate',
                ], 503);
            }
            $data['migrations_table'] = true;
            $data['migrations_count'] = DB::table('migrations')->count();
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'database' => 'connected',
                'migrations' => 'error',
                'error' => $e->getMessage(),
                'hint' => 'Jalankan: php artisan migrate',
            ], 503);
        }

        $data['ok'] = true;

        return response()->json($data);
    }
}
