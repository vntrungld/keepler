<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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

    /**
     * Keeps the deployment awake. The free instance sleeps after 15 minutes
     * without inbound traffic and costs about a minute to boot again, so an
     * external scheduler pings this every few minutes. The query is what makes
     * it worth a route of its own: the managed database sleeps on its own
     * schedule too, and would otherwise only wake on a real user's first
     * request.
     */
    public function warm(): JsonResponse
    {
        DB::select('select 1');

        return response()->json(['status' => 'ok']);
    }
}
