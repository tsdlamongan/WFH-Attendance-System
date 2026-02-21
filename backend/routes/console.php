<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule WhatsApp recap command to run hourly
Schedule::command('whatsapp:send-recap')->hourly();

Schedule::command('attendance:auto-checkout')->everyMinute();
