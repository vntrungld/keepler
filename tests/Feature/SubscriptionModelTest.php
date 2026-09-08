<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
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

        $sub = $user->subscriptions()->create([
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

        $sub = $user->subscriptions()->create([
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
        $user->subscriptions()->create([
            'name' => 'ChatGPT',
            'amount' => 20,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
        ]);

        $this->assertCount(1, $user->subscriptions);
    }

    public function test_subscribed_days_and_total_spent_are_computed_from_started_at(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->for($user)->create([
            'amount' => 100000,
            'currency' => 'VND',
            'billing_cycle' => 'monthly',
            'started_at' => now()->subDays(65)->toDateString(),
        ]);

        $this->assertSame(65, $subscription->subscribed_days);
        // 65 days / 30-day cycle = 2 completed cycles.
        $this->assertSame(200000.0, $subscription->total_spent);
    }

    public function test_total_spent_is_zero_before_the_first_cycle_completes(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->for($user)->create([
            'amount' => 50000,
            'currency' => 'VND',
            'started_at' => now()->toDateString(),
        ]);

        $this->assertSame(0.0, $subscription->total_spent);
    }

    public function test_subscription_belongs_to_a_payment_method(): void
    {
        $user = User::factory()->create();
        $method = PaymentMethod::factory()->for($user)->create();
        $subscription = Subscription::factory()->for($user)->create(['payment_method_id' => $method->id]);

        $this->assertTrue($subscription->paymentMethod->is($method));
    }

    public function test_subscribed_days_and_total_spent_fall_back_to_zero_when_started_at_and_created_at_are_not_selected(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create([
            'amount' => 100000,
            'currency' => 'VND',
            'billing_cycle' => 'monthly',
            'started_at' => now()->subDays(65)->toDateString(),
        ]);

        // Mirrors the shape DashboardController used before it started
        // selecting started_at/created_at: neither column is present, so
        // the model can't compute a real subscribed_days figure and must
        // fall back to its last-resort `now()` guard instead of crashing.
        $subscription = Subscription::query()->get([
            'id', 'name', 'amount', 'currency', 'amount_vnd',
            'billing_cycle', 'next_renewal_date', 'status',
        ])->first();

        $this->assertSame(0, $subscription->subscribed_days);
        $this->assertSame(0.0, $subscription->total_spent);
    }

    public function test_total_periods_computes_ends_at_as_the_last_billing_date(): void
    {
        $user = User::factory()->create();

        $sub = $user->subscriptions()->create([
            'name' => 'iPhone tra gop',
            'amount' => 2000000,
            'currency' => 'VND',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-10-05',
            'started_at' => '2026-09-05',
            'total_periods' => 12,
        ]);

        $this->assertSame('2027-08-05', $sub->ends_at->toDateString());
    }

    public function test_ends_at_clamps_to_the_end_of_a_short_month(): void
    {
        $user = User::factory()->create();

        $sub = $user->subscriptions()->create([
            'name' => 'Khoa hoc',
            'amount' => 500000,
            'currency' => 'VND',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-02-28',
            'started_at' => '2026-01-31',
            'total_periods' => 2,
        ]);

        $this->assertSame('2026-02-28', $sub->ends_at->toDateString());
    }

    public function test_a_subscription_without_total_periods_has_no_end_date(): void
    {
        $user = User::factory()->create();

        $sub = $user->subscriptions()->create([
            'name' => 'Netflix',
            'amount' => 260000,
            'currency' => 'VND',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-10-05',
            'started_at' => '2026-09-05',
        ]);

        $this->assertNull($sub->ends_at);
        $this->assertNull($sub->periods_remaining);
        $this->assertFalse($sub->has_ended);
    }

    public function test_periods_remaining_counts_the_billings_left_after_the_ones_already_paid(): void
    {
        $user = User::factory()->create();

        $sub = $user->subscriptions()->create([
            'name' => 'Macbook tra gop',
            'amount' => 3000000,
            'currency' => 'VND',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-12-05',
            'started_at' => '2026-09-05',
            'total_periods' => 12,
        ]);

        $this->assertSame(3, $sub->periods_paid);
        $this->assertSame(9, $sub->periods_remaining);
    }

    public function test_a_subscription_past_its_last_billing_date_has_ended(): void
    {
        $user = User::factory()->create();
        $this->travelTo('2027-09-06');

        $sub = $user->subscriptions()->create([
            'name' => 'Macbook tra gop',
            'amount' => 3000000,
            'currency' => 'VND',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2027-08-05',
            'started_at' => '2026-09-05',
            'total_periods' => 12,
        ]);

        $this->assertTrue($sub->has_ended);
        $this->assertSame(0, $sub->periods_remaining);
    }

    public function test_a_subscription_on_its_last_billing_date_has_not_ended_yet(): void
    {
        $user = User::factory()->create();
        $this->travelTo('2027-08-05');

        $sub = $user->subscriptions()->create([
            'name' => 'Macbook tra gop',
            'amount' => 3000000,
            'currency' => 'VND',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2027-08-05',
            'started_at' => '2026-09-05',
            'total_periods' => 12,
        ]);

        $this->assertFalse($sub->has_ended);
        $this->assertSame(1, $sub->periods_remaining);
    }

    public function test_total_spent_stops_growing_once_every_period_is_paid(): void
    {
        $user = User::factory()->create();
        $this->travelTo('2029-09-05');

        $sub = $user->subscriptions()->create([
            'name' => 'Macbook tra gop',
            'amount' => 3000000,
            'currency' => 'VND',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2027-08-05',
            'started_at' => '2026-09-05',
            'total_periods' => 12,
        ]);

        $this->assertSame(36000000.0, $sub->total_spent);
    }
}
