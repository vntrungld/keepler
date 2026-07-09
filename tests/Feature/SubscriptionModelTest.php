<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('currency.rates', ['VND' => 1, 'USD' => 26000]);
    }

    public function test_creating_a_subscription_auto_computes_amount_vnd_from_currency(): void
    {
        $user = User::factory()->create();

        $sub = Subscription::create([
            'user_id' => $user->id,
            'name' => 'Netflix',
            'amount' => 9.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
            'status' => 'active',
        ]);

        $this->assertSame(259740, $sub->amount_vnd);
    }

    public function test_vnd_subscription_keeps_amount_as_amount_vnd(): void
    {
        $user = User::factory()->create();

        $sub = Subscription::create([
            'user_id' => $user->id,
            'name' => 'Spotify VN',
            'amount' => 59000,
            'currency' => 'VND',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
        ]);

        $this->assertSame(59000, $sub->amount_vnd);
    }

    public function test_a_user_has_many_subscriptions(): void
    {
        $user = User::factory()->create();
        Subscription::create([
            'user_id' => $user->id,
            'name' => 'ChatGPT',
            'amount' => 20,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
        ]);

        $this->assertCount(1, $user->subscriptions);
    }
}
