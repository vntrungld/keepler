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

    public function create(Request $request)
    {
        return Inertia::render('Subscriptions/Create', [
            'currencies' => CurrencyConverter::supportedCurrencies(),
            'paymentMethods' => $request->user()->paymentMethods()->get(['id', 'label']),
        ]);
    }

    public function store(SubscriptionRequest $request)
    {
        $subscription = $request->user()->subscriptions()->create($request->validated());

        $subscription->events()->create([
            'kind' => 'subscribed',
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
            'occurred_at' => $subscription->started_at?->toDateString() ?? now()->toDateString(),
        ]);

        return redirect('/subscriptions');
    }

    public function show(Subscription $subscription)
    {
        $this->authorize('view', $subscription);

        $subscription->load('paymentMethod', 'events');

        return Inertia::render('Subscriptions/Show', [
            'subscription' => $subscription,
        ]);
    }

    public function edit(Request $request, Subscription $subscription)
    {
        $this->authorize('update', $subscription);

        return Inertia::render('Subscriptions/Edit', [
            'subscription' => $subscription,
            'currencies' => CurrencyConverter::supportedCurrencies(),
            'paymentMethods' => $request->user()->paymentMethods()->get(['id', 'label']),
        ]);
    }

    public function update(SubscriptionRequest $request, Subscription $subscription)
    {
        $this->authorize('update', $subscription);

        $originalAmount = (float) $subscription->amount;
        $originalStatus = $subscription->status;

        $subscription->update($request->validated());

        if ((float) $subscription->amount !== $originalAmount) {
            $subscription->events()->create([
                'kind' => 'price_changed',
                'amount' => $subscription->amount,
                'currency' => $subscription->currency,
                'occurred_at' => now()->toDateString(),
            ]);
        }

        if ($originalStatus !== 'cancelled' && $subscription->status === 'cancelled') {
            $subscription->events()->create([
                'kind' => 'cancelled',
                'occurred_at' => now()->toDateString(),
            ]);
        }

        return redirect('/subscriptions');
    }

    public function destroy(Subscription $subscription)
    {
        $this->authorize('delete', $subscription);

        $subscription->delete();

        return redirect('/subscriptions');
    }
}
