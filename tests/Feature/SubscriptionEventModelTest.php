<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionEventModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_belong_to_a_subscription_and_are_ordered_newest_first(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->for($user)->create();

        $older = $subscription->events()->create([
            'kind' => 'subscribed',
            'amount' => 9.99,
            'currency' => 'USD',
            'occurred_at' => now()->subDays(30)->toDateString(),
        ]);
        $newer = $subscription->events()->create([
            'kind' => 'price_changed',
            'amount' => 12.99,
            'currency' => 'USD',
            'occurred_at' => now()->toDateString(),
        ]);

        $ids = $subscription->events()->pluck('id')->all();
        $this->assertSame([$newer->id, $older->id], $ids);
    }
}
