<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $subscriptions = $request->user()->subscriptions()
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->get([
                'id', 'name', 'amount', 'currency', 'amount_vnd',
                'billing_cycle', 'next_renewal_date', 'status',
            ]);

        return Inertia::render('Dashboard', [
            'subscriptions' => $subscriptions,
        ]);
    }
}
