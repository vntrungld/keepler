<?php

namespace Tests\Feature\Gmail;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GmailScanImportTest extends TestCase
{
    use RefreshDatabase;

    private function connectedUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'gmail_access_token' => 'a', 'gmail_refresh_token' => 'r',
            'gmail_token_expires_at' => now()->addHour(),
        ])->save();

        return $user->fresh();
    }

    public function test_import_creates_new_subscriptions_scoped_to_user(): void
    {
        $user = $this->connectedUser();

        $this->actingAs($user)->post('/gmail/import', [
            'items' => [[
                'action' => 'create', 'provider_key' => 'netflix', 'name' => 'Netflix',
                'amount' => 12.99, 'currency' => 'USD', 'billing_cycle' => 'monthly',
                'next_renewal_date' => '2026-08-01', 'cancel_url' => 'https://netflix.com/cancelplan',
                'duplicate_of' => null,
            ]],
        ])->assertRedirect(route('dashboard'));

        $sub = $user->subscriptions()->first();
        $this->assertSame('netflix', $sub->provider_key);
        $this->assertSame('active', $sub->status);
        $this->assertGreaterThan(0, $sub->amount_vnd); // saving hook computed it
    }

    public function test_import_creates_a_subscribed_event_for_the_new_subscription(): void
    {
        $user = $this->connectedUser();

        $this->actingAs($user)->post('/gmail/import', [
            'items' => [[
                'action' => 'create', 'provider_key' => 'netflix', 'name' => 'Netflix',
                'amount' => 12.99, 'currency' => 'USD', 'billing_cycle' => 'monthly',
                'next_renewal_date' => '2026-08-01', 'cancel_url' => 'https://netflix.com/cancelplan',
                'duplicate_of' => null,
            ]],
        ])->assertRedirect(route('dashboard'));

        $sub = $user->subscriptions()->first();
        $this->assertDatabaseHas('subscription_events', [
            'subscription_id' => $sub->id,
            'kind' => 'subscribed',
        ]);
        $this->assertSame(1, $sub->events()->count());
    }

    public function test_import_update_status_marks_existing_cancelled(): void
    {
        $user = $this->connectedUser();
        $sub = $user->subscriptions()->create([
            'name' => 'Netflix', 'provider_key' => 'netflix', 'amount' => 12.99,
            'currency' => 'USD', 'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-07-20', 'status' => 'active',
        ]);

        $this->actingAs($user)->post('/gmail/import', [
            'items' => [['action' => 'update_status', 'duplicate_of' => $sub->id]],
        ])->assertRedirect(route('dashboard'));

        $this->assertSame('cancelled', $sub->fresh()->status);
        $this->assertDatabaseHas('subscription_events', [
            'subscription_id' => $sub->id,
            'kind' => 'cancelled',
        ]);
    }

    public function test_import_cannot_touch_another_users_subscription(): void
    {
        $user = $this->connectedUser();
        $other = User::factory()->create();
        $victim = $other->subscriptions()->create([
            'name' => 'Netflix', 'provider_key' => 'netflix', 'amount' => 12.99,
            'currency' => 'USD', 'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-07-20', 'status' => 'active',
        ]);

        $this->actingAs($user)->post('/gmail/import', [
            'items' => [['action' => 'update_status', 'duplicate_of' => $victim->id]],
        ]);

        $this->assertSame('active', $victim->fresh()->status); // untouched
    }
}
