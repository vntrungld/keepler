<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionRenewalReminder;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
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

    public function test_a_failing_notification_does_not_abort_the_run_for_other_subscriptions(): void
    {
        $failingUser = User::factory()->create(['reminder_days_before' => 3]);
        $okUser = User::factory()->create(['reminder_days_before' => 3]);

        $renewalDate = now()->addDays(3)->toDateString();

        $failingSubscription = Subscription::factory()->for($failingUser)->create([
            'status' => 'active',
            'next_renewal_date' => $renewalDate,
        ]);
        $okSubscription = Subscription::factory()->for($okUser)->create([
            'status' => 'active',
            'next_renewal_date' => $renewalDate,
        ]);

        $sentTo = [];

        // Replace the notification dispatcher (rather than Notification::fake(),
        // which never actually throws) with one that throws only for the
        // failing user, so we can prove the command survives a single bad
        // transport call instead of aborting the whole chunk.
        $this->app->bind(Dispatcher::class, function () use (&$sentTo, $failingUser) {
            return new class($sentTo, $failingUser->id) implements Dispatcher
            {
                public array $sentTo;

                public function __construct(array &$sentTo, private readonly int $failingUserId)
                {
                    $this->sentTo = &$sentTo;
                }

                public function send($notifiables, $notification)
                {
                    foreach (Arr::wrap($notifiables) as $notifiable) {
                        if ($notifiable->id === $this->failingUserId) {
                            throw new \RuntimeException('Simulated transport failure');
                        }

                        $this->sentTo[] = $notifiable->id;
                    }
                }

                public function sendNow($notifiables, $notification, ?array $channels = null)
                {
                    $this->send($notifiables, $notification);
                }
            };
        });

        $this->artisan('subscriptions:send-renewal-reminders')->assertExitCode(0);

        $this->assertContains($okUser->id, $sentTo);
        $this->assertNotContains($failingUser->id, $sentTo);

        $this->assertSame(
            $okSubscription->next_renewal_date->toDateString(),
            $okSubscription->fresh()->last_reminder_sent_for?->toDateString(),
        );
        $this->assertNull($failingSubscription->fresh()->last_reminder_sent_for);
    }

    public function test_does_not_send_for_a_plan_whose_final_period_has_already_been_paid(): void
    {
        Notification::fake();

        $user = User::factory()->create(['reminder_days_before' => 3]);
        Subscription::factory()->for($user)->create([
            'status' => 'active',
            'started_at' => now()->subMonths(6)->toDateString(),
            'total_periods' => 3,
            'next_renewal_date' => now()->addDays(3)->toDateString(),
        ]);

        $this->artisan('subscriptions:send-renewal-reminders');

        Notification::assertNotSentTo($user, SubscriptionRenewalReminder::class);
    }
}
