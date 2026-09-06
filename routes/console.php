<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Kept for hosts that run a real cron. The production deployment sleeps when
// idle and never runs `schedule:run`, so an external scheduler posts to
// `cron.renewal-reminders` instead. Running both is safe: the command skips a
// subscription whose `last_reminder_sent_for` already matches its renewal date.
Schedule::command('subscriptions:send-renewal-reminders')->dailyAt('08:00')->timezone('Asia/Ho_Chi_Minh');
