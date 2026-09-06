<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * Entry points for an external scheduler. The free hosting tier sleeps when
 * idle and runs no cron of its own, so the daily reminder run is triggered
 * over HTTP instead of by the framework scheduler.
 */
class CronController extends Controller
{
    public function renewalReminders(): JsonResponse
    {
        // Called directly rather than through `schedule:run`, which only fires
        // a task during its exact due minute and would depend on the external
        // scheduler hitting that minute.
        Artisan::call('subscriptions:send-renewal-reminders');

        return response()->json(['status' => 'ok']);
    }
}
