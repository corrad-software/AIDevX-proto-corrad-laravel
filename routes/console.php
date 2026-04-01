<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sync Desk365 tickets ke AI Vector Store setiap 5 minit
Schedule::command('kerisi:process-tickets --from-api --upload')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);
