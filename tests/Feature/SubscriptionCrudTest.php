<?php

namespace Tests\Feature;

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
}
