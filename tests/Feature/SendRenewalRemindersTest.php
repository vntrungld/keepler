<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionRenewalReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendRenewalRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_a_reminder_when_the_renewal_is_exactly_reminder_days_before_away(): void
    {
        Notification::fake();

        $user = User::factory()->create(['reminder_days_before' => 3]);
        $subscription = Subscription::factory()->for($user)->create([
            'status' => 'active',
            'next_renewal_date' => now()->addDays(3)->toDateString(),
        ]);

        $this->artisan('subscriptions:send-renewal-reminders')->assertExitCode(0);

        Notification::assertSentTo($user, SubscriptionRenewalReminder::class);
        $this->assertSame(
            $subscription->fresh()->next_renewal_date->toDateString(),
            $subscription->fresh()->last_reminder_sent_for->toDateString(),
        );
    }

    public function test_does_not_send_twice_for_the_same_renewal_date(): void
    {
        Notification::fake();

        $user = User::factory()->create(['reminder_days_before' => 3]);
        $renewalDate = now()->addDays(3)->toDateString();
        Subscription::factory()->for($user)->create([
            'status' => 'active',
            'next_renewal_date' => $renewalDate,
            'last_reminder_sent_for' => $renewalDate,
        ]);

        $this->artisan('subscriptions:send-renewal-reminders');

        Notification::assertNotSentTo($user, SubscriptionRenewalReminder::class);
    }

    public function test_does_not_send_when_reminders_are_disabled(): void
    {
        Notification::fake();

        $user = User::factory()->create(['renewal_reminders_enabled' => false, 'reminder_days_before' => 3]);
        Subscription::factory()->for($user)->create([
            'status' => 'active',
            'next_renewal_date' => now()->addDays(3)->toDateString(),
        ]);

        $this->artisan('subscriptions:send-renewal-reminders');

        Notification::assertNotSentTo($user, SubscriptionRenewalReminder::class);
    }

    public function test_does_not_send_when_the_subscription_is_cancelled(): void
    {
        Notification::fake();

        $user = User::factory()->create(['reminder_days_before' => 3]);
        Subscription::factory()->for($user)->create([
            'status' => 'cancelled',
            'next_renewal_date' => now()->addDays(3)->toDateString(),
        ]);

        $this->artisan('subscriptions:send-renewal-reminders');

        Notification::assertNothingSent();
    }

    public function test_does_not_send_before_the_reminder_window(): void
    {
        Notification::fake();

        $user = User::factory()->create(['reminder_days_before' => 3]);
        Subscription::factory()->for($user)->create([
            'status' => 'active',
            'next_renewal_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->artisan('subscriptions:send-renewal-reminders');

        Notification::assertNothingSent();
    }
}
