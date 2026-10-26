<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reset the email delay counter at midnight to prevent indefinite growth
Schedule::call(function () {
    Cache::forget('email_delay_counter');
})
    ->dailyAt('00:00')
    ->name('reset-email-delay-counter')
    ->withoutOverlapping();

// Plain-paper sheet PNGs live ~1h; sweep leftovers every 30 minutes.
Schedule::command('credential-sheets:purge-expired')
    ->everyThirtyMinutes()
    ->name('purge-expired-credential-sheets')
    ->withoutOverlapping();
