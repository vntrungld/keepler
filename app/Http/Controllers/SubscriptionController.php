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
            'services' => $this->serviceCatalog(),
        ]);
    }

    /**
     * The catalog rows the "add subscription" picker pre-fills the form from.
     *
     * Only the fields the form actually writes are sent: sender domains and
     * detection keywords are Gmail-scanning internals with no business in the
     * browser, and shipping all of them would triple the payload.
     *
     * Currency is deliberately absent — the catalog lists USD prices, but the
     * form defaults to VND and that is usually what the user is billed.
     *
     * @return array<int,array{name:string,default_cycle:string,cancel_url:string}>
     */
    private function serviceCatalog(): array
    {
        return collect(config('providers'))
            ->except('_aggregators')
            ->map(fn (array $service): array => [
                'name' => $service['name'],
                'default_cycle' => $service['default_cycle'],
                'cancel_url' => $service['cancel_url'],
            ])
            ->values()
            ->all();
    }

    public function store(SubscriptionRequest $request)
    {
        $request->user()->subscriptions()->create($request->validated());

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
