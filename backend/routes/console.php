<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recap times are configurable to the minute, so check every minute which teams are due
Schedule::command('whatsapp:send-recap')->everyMinute();

Schedule::command('attendance:auto-checkout')->everyMinute();
