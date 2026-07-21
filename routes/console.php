<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fire any due recurring billing schedules. Day-granular, so hourly polling is
// enough; requires the scheduler to be running (php artisan schedule:work).
Schedule::command('billing:run-scheduled')->hourly()->withoutOverlapping();
