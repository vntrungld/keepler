<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionRenewalReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CronRenewalRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_the_reminder_command_when_the_token_matches(): void
    {
        Notification::fake();
        config(['services.cron.token' => 'the-cron-token']);

        $user = $this->userWithReminderDueToday();

        $response = $this->withHeader('X-Cron-Token', 'the-cron-token')
            ->postJson('/cron/renewal-reminders');

        $response->assertOk();
        Notification::assertSentTo($user, SubscriptionRenewalReminder::class);
    }

    public function test_rejects_a_request_without_a_token(): void
    {
        Notification::fake();
        config(['services.cron.token' => 'the-cron-token']);

        $this->userWithReminderDueToday();

        $this->postJson('/cron/renewal-reminders')->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_rejects_a_request_with_the_wrong_token(): void
    {
        Notification::fake();
        config(['services.cron.token' => 'the-cron-token']);

        $this->userWithReminderDueToday();

        $this->withHeader('X-Cron-Token', 'not-the-cron-token')
            ->postJson('/cron/renewal-reminders')
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    /**
     * Failing closed matters more than the other cases: forgetting to set
     * CRON_TOKEN must not leave the endpoint open to anyone who guesses it.
     */
    public function test_rejects_every_request_when_no_token_is_configured(): void
    {
        Notification::fake();
        config(['services.cron.token' => null]);

        $this->userWithReminderDueToday();

        $this->withHeader('X-Cron-Token', 'anything')
            ->postJson('/cron/renewal-reminders')
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    private function userWithReminderDueToday(): User
    {
        $user = User::factory()->create(['reminder_days_before' => 3]);

        Subscription::factory()->for($user)->create([
            'status' => 'active',
            'next_renewal_date' => now()->addDays(3)->toDateString(),
        ]);

        return $user;
    }
}
