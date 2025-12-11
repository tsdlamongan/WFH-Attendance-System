<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule auto-checkout command to run daily at 23:59
Schedule::command('attendance:auto-checkout')->dailyAt('23:59');

// Schedule WhatsApp recap command to run hourly
Schedule::command('whatsapp:send-recap')->hourly();
