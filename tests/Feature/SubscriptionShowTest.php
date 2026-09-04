<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubscriptionShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_their_subscription_detail_page(): void
    {
        $user = User::factory()->create();
        $sub = Subscription::factory()->for($user)->create();

        $this->actingAs($user)
            ->get("/subscriptions/{$sub->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Show')
                ->where('subscription.id', $sub->id)
                ->has('subscription.subscribed_days')
                ->has('subscription.total_spent')
            );
    }

    public function test_a_user_cannot_view_another_users_subscription(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $sub = Subscription::factory()->for($owner)->create();

        $this->actingAs($other)->get("/subscriptions/{$sub->id}")->assertForbidden();
    }
}
