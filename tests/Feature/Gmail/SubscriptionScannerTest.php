<?php

namespace Tests\Feature\Gmail;

use App\Models\Subscription;
use App\Models\User;
use App\Support\Gmail\GmailClient;
use App\Support\Gmail\SubscriptionScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionScannerTest extends TestCase
{
    use RefreshDatabase;

    /** Build a scanner whose GmailClient is stubbed to return the given messages. */
    private function scannerReturning(array $messagesById): SubscriptionScanner
    {
        $client = new class($messagesById) extends GmailClient {
            public function __construct(private array $messages)
            {
            }

            public function listMessageIds(string $query, int $max = 100): array
            {
                return array_keys($this->messages);
            }

            public function getMessage(string $id): array
            {
                return $this->messages[$id];
            }
        };

        return new SubscriptionScanner($client);
    }

    public function test_payment_email_becomes_a_create_candidate(): void
    {
        $user = User::factory()->create();
        $scanner = $this->scannerReturning([
            'm1' => [
                'from' => 'info@netflix.com',
                'subject' => 'Your Netflix receipt',
                'date' => '2026-07-01',
                'body' => 'You were charged $12.99 this month.',
            ],
        ]);

        $candidates = $scanner->scan($user);

        $this->assertCount(1, $candidates);
        $this->assertSame('netflix', $candidates[0]['provider_key']);
        $this->assertSame('create', $candidates[0]['action']);
        $this->assertSame(12.99, $candidates[0]['amount']);
    }

    public function test_payment_for_existing_subscription_is_skipped_as_duplicate(): void
    {
        $user = User::factory()->create();
        $existing = $user->subscriptions()->create([
            'name' => 'Netflix', 'provider_key' => 'netflix', 'amount' => 12.99,
            'currency' => 'USD', 'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-07-20', 'status' => 'active',
        ]);

        $scanner = $this->scannerReturning([
            'm1' => [
                'from' => 'info@netflix.com', 'subject' => 'Your Netflix receipt',
                'date' => '2026-07-01', 'body' => 'Charged $12.99 monthly.',
            ],
        ]);

        $candidates = $scanner->scan($user);

        $this->assertSame('skip', $candidates[0]['action']);
        $this->assertSame($existing->id, $candidates[0]['duplicate_of']);
    }

    public function test_cancellation_matching_active_sub_becomes_update_status(): void
    {
        $user = User::factory()->create();
        $existing = $user->subscriptions()->create([
            'name' => 'Netflix', 'provider_key' => 'netflix', 'amount' => 12.99,
            'currency' => 'USD', 'billing_cycle' => 'monthly',
            'next_renewal_date' => '2026-07-20', 'status' => 'active',
        ]);

        $scanner = $this->scannerReturning([
            'm1' => [
                'from' => 'info@netflix.com',
                'subject' => 'Your Netflix membership has been cancelled',
                'date' => '2026-07-10', 'body' => 'Your membership ended.',
            ],
        ]);

        $candidates = $scanner->scan($user);

        $this->assertSame('update_status', $candidates[0]['action']);
        $this->assertSame($existing->id, $candidates[0]['duplicate_of']);
    }

    public function test_cancellation_without_a_matching_sub_is_skipped(): void
    {
        $user = User::factory()->create();
        $scanner = $this->scannerReturning([
            'm1' => [
                'from' => 'info@netflix.com',
                'subject' => 'Your Netflix membership has been cancelled',
                'date' => '2026-07-10', 'body' => 'Your membership ended.',
            ],
        ]);

        $candidates = $scanner->scan($user);

        $this->assertSame('skip', $candidates[0]['action']);
        $this->assertNull($candidates[0]['duplicate_of']);
    }

    public function test_latest_email_per_provider_and_cycle_wins(): void
    {
        $user = User::factory()->create();
        $scanner = $this->scannerReturning([
            'older' => [
                'from' => 'info@netflix.com', 'subject' => 'receipt',
                'date' => '2026-05-01', 'body' => 'Charged $12.99 monthly.',
            ],
            'newer' => [
                'from' => 'info@netflix.com', 'subject' => 'membership cancelled',
                'date' => '2026-07-01', 'body' => 'Your membership ended.',
            ],
        ]);

        $candidates = $scanner->scan($user);

        $this->assertCount(1, $candidates);
        $this->assertSame('cancellation', $candidates[0]['intent']);
    }
}
