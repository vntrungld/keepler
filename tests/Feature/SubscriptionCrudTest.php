<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('currency.rates', ['VND' => 1, 'USD' => 26000]);
    }

    public function test_guest_is_redirected_from_subscriptions_index(): void
    {
        $this->get('/subscriptions')->assertRedirect('/login');
    }

    public function test_user_can_store_a_subscription_and_amount_vnd_is_computed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/subscriptions', [
            'name' => 'Netflix',
            'amount' => 9.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
            'status' => 'active',
        ]);

        $response->assertRedirect('/subscriptions');
        $sub = Subscription::first();
        $this->assertSame($user->id, $sub->user_id);
        $this->assertSame(259740, $sub->amount_vnd);
    }

    public function test_store_rejects_an_unsupported_currency(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/subscriptions', [
            'name' => 'Foo',
            'amount' => 10,
            'currency' => 'EUR',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
            'status' => 'active',
        ])->assertSessionHasErrors('currency');
    }

    public function test_user_cannot_update_another_users_subscription(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $sub = Subscription::factory()->for($owner)->create();

        $this->actingAs($other)->put("/subscriptions/{$sub->id}", [
            'name' => 'Hacked',
            'amount' => 1,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
            'status' => 'active',
        ])->assertForbidden();
    }

    public function test_user_cannot_delete_another_users_subscription(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $sub = Subscription::factory()->for($owner)->create();

        $this->actingAs($other)->delete("/subscriptions/{$sub->id}")->assertForbidden();
        $this->assertDatabaseHas('subscriptions', ['id' => $sub->id]);
    }

    public function test_user_can_delete_own_subscription(): void
    {
        $user = User::factory()->create();
        $sub = Subscription::factory()->for($user)->create();

        $this->actingAs($user)->delete("/subscriptions/{$sub->id}")->assertRedirect('/subscriptions');
        $this->assertDatabaseMissing('subscriptions', ['id' => $sub->id]);
    }

    public function test_user_can_store_a_subscription_with_the_new_optional_fields(): void
    {
        $user = User::factory()->create();
        $method = PaymentMethod::factory()->for($user)->create();

        $this->actingAs($user)->post('/subscriptions', [
            'name' => 'Netflix',
            'amount' => 9.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
            'status' => 'active',
            'list' => 'business',
            'category' => 'Streaming',
            'payment_method_id' => $method->id,
            'is_trial' => true,
            'started_at' => '2026-01-15',
        ])->assertRedirect('/subscriptions');

        $sub = Subscription::first();
        $this->assertSame('business', $sub->list);
        $this->assertSame('Streaming', $sub->category);
        $this->assertSame($method->id, $sub->payment_method_id);
        $this->assertTrue($sub->is_trial);
        $this->assertSame('2026-01-15', $sub->started_at->toDateString());
    }

    public function test_a_users_payment_method_cannot_be_assigned_to_another_users_subscription(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $method = PaymentMethod::factory()->for($owner)->create();

        $this->actingAs($other)->post('/subscriptions', [
            'name' => 'Netflix',
            'amount' => 9.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
            'status' => 'active',
            'payment_method_id' => $method->id,
        ])->assertSessionHasErrors('payment_method_id');
    }

    public function test_list_defaults_to_personal_when_omitted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/subscriptions', [
            'name' => 'Netflix',
            'amount' => 9.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
            'status' => 'active',
        ])->assertRedirect('/subscriptions');

        $this->assertSame('personal', Subscription::first()->list);
    }

    public function test_updating_the_amount_logs_a_price_changed_event(): void
    {
        $user = User::factory()->create();
        $sub = Subscription::factory()->for($user)->create(['amount' => 9.99]);

        $this->actingAs($user)->put("/subscriptions/{$sub->id}", [
            'name' => $sub->name,
            'amount' => 14.99,
            'currency' => $sub->currency,
            'billing_cycle' => $sub->billing_cycle,
            'next_renewal_date' => $sub->next_renewal_date->toDateString(),
            'status' => 'active',
        ])->assertRedirect('/subscriptions');

        $this->assertDatabaseHas('subscription_events', [
            'subscription_id' => $sub->id,
            'kind' => 'price_changed',
        ]);
    }

    public function test_marking_active_as_cancelled_logs_a_cancelled_event(): void
    {
        $user = User::factory()->create();
        $sub = Subscription::factory()->for($user)->create(['status' => 'active']);

        $this->actingAs($user)->put("/subscriptions/{$sub->id}", [
            'name' => $sub->name,
            'amount' => $sub->amount,
            'currency' => $sub->currency,
            'billing_cycle' => $sub->billing_cycle,
            'next_renewal_date' => $sub->next_renewal_date->toDateString(),
            'status' => 'cancelled',
        ])->assertRedirect('/subscriptions');

        $this->assertDatabaseHas('subscription_events', [
            'subscription_id' => $sub->id,
            'kind' => 'cancelled',
        ]);
    }

    public function test_storing_a_subscription_logs_a_subscribed_event(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/subscriptions', [
            'name' => 'Netflix',
            'amount' => 9.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
            'status' => 'active',
        ]);

        $sub = Subscription::first();
        $this->assertDatabaseHas('subscription_events', [
            'subscription_id' => $sub->id,
            'kind' => 'subscribed',
        ]);
    }

    public function test_storing_a_subscription_logs_the_subscribed_event_exactly_once(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/subscriptions', [
            'name' => 'Netflix',
            'amount' => 9.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-08-01',
            'status' => 'active',
        ]);

        $sub = Subscription::first();
        $this->assertSame(1, $sub->events()->where('kind', 'subscribed')->count());
    }

    public function test_updating_the_amount_logs_the_price_changed_event_exactly_once(): void
    {
        $user = User::factory()->create();
        $sub = Subscription::factory()->for($user)->create(['amount' => 9.99]);

        $this->actingAs($user)->put("/subscriptions/{$sub->id}", [
            'name' => $sub->name,
            'amount' => 14.99,
            'currency' => $sub->currency,
            'billing_cycle' => $sub->billing_cycle,
            'next_renewal_date' => $sub->next_renewal_date->toDateString(),
            'status' => 'active',
        ])->assertRedirect('/subscriptions');

        $this->assertSame(1, $sub->events()->where('kind', 'price_changed')->count());
    }

    public function test_marking_active_as_cancelled_logs_the_cancelled_event_exactly_once(): void
    {
        $user = User::factory()->create();
        $sub = Subscription::factory()->for($user)->create(['status' => 'active']);

        $this->actingAs($user)->put("/subscriptions/{$sub->id}", [
            'name' => $sub->name,
            'amount' => $sub->amount,
            'currency' => $sub->currency,
            'billing_cycle' => $sub->billing_cycle,
            'next_renewal_date' => $sub->next_renewal_date->toDateString(),
            'status' => 'cancelled',
        ])->assertRedirect('/subscriptions');

        $this->assertSame(1, $sub->events()->where('kind', 'cancelled')->count());
    }
}
