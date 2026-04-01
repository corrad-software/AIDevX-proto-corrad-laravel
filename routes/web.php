<?php

use App\Http\Controllers\CorradSsoController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

$spaPath = public_path('spa/index.html');
$spaExists = file_exists($spaPath);

/** Headers supaya pelayar tidak cache `index.html` lama selepas `npm run build:laravel`. */
$spaHtmlHeaders = [
    'Content-Type' => 'text/html; charset=UTF-8',
    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
    'Pragma' => 'no-cache',
];

/*
|--------------------------------------------------------------------------
| CORRAD → KAIS SSO (must be before SPA fallback)
|--------------------------------------------------------------------------
*/
Route::get('/auth/corrad-sso', [CorradSsoController::class, 'login'])
    ->middleware('throttle:30,1');

Route::get('/', function () use ($spaPath, $spaExists) {
    if ($spaExists) {
        return response()->file($spaPath, ['Content-Type' => 'text/html']);
    }

    return view('welcome');
});

Broadcast::routes(['middleware' => ['web', 'auth:sanctum']]);

/*
|--------------------------------------------------------------------------
| SPA fallback (same-origin mode)
|--------------------------------------------------------------------------
| When public/spa/index.html exists (after npm run build:laravel), serve
| the SPA for admin/storefront routes. Use http://localhost:8090 to avoid
| CORS and "Sambungan gagal" errors.
*/
if ($spaExists) {
    Route::fallback(function () use ($spaPath, $spaHtmlHeaders) {
        return response()->file($spaPath, $spaHtmlHeaders);
    });
}
