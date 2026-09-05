<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $subscriptions = $request->user()->subscriptions()
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->get([
                'id', 'name', 'provider_key', 'amount', 'currency', 'amount_vnd',
                'billing_cycle', 'next_renewal_date', 'status', 'started_at', 'created_at',
            ]);

        return Inertia::render('Calendar/Index', [
            'subscriptions' => $subscriptions,
        ]);
    }
}
