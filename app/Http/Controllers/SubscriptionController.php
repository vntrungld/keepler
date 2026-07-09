<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubscriptionRequest;
use App\Models\Subscription;
use App\Support\CurrencyConverter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Subscriptions/Index', [
            'subscriptions' => $request->user()->subscriptions()->latest()->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Subscriptions/Create', [
            'currencies' => CurrencyConverter::supportedCurrencies(),
        ]);
    }

    public function store(SubscriptionRequest $request)
    {
        $request->user()->subscriptions()->create($request->validated());

        return redirect('/subscriptions');
    }

    public function edit(Subscription $subscription)
    {
        $this->authorize('update', $subscription);

        return Inertia::render('Subscriptions/Edit', [
            'subscription' => $subscription,
            'currencies' => CurrencyConverter::supportedCurrencies(),
        ]);
    }

    public function update(SubscriptionRequest $request, Subscription $subscription)
    {
        $this->authorize('update', $subscription);

        $subscription->update($request->validated());

        return redirect('/subscriptions');
    }

    public function destroy(Subscription $subscription)
    {
        $this->authorize('delete', $subscription);

        $subscription->delete();

        return redirect('/subscriptions');
    }
}
