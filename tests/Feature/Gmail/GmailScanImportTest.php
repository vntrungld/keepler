<?php

namespace Tests\Feature\Gmail;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_scan_redirects_to_connect_when_not_connected(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/gmail/scan')
            ->assertRedirect(route('gmail.connect'));
    }

    public function test_scan_renders_candidates(): void
    {
        $netflixBody = rtrim(strtr(base64_encode('Charged $12.99 monthly.'), '+/', '-_'), '=');
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages/m1*' => Http::response([
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'info@netflix.com'],
                        ['name' => 'Subject', 'value' => 'Your Netflix receipt'],
                        ['name' => 'Date', 'value' => 'Wed, 01 Jul 2026 10:00:00 +0000'],
                    ],
                    'mimeType' => 'text/plain',
                    'body' => ['data' => $netflixBody],
                ],
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/messages*' => Http::response([
                'messages' => [['id' => 'm1']],
            ]),
        ]);

        $this->actingAs($this->connectedUser())
            ->get('/gmail/scan')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Gmail/ScanResults')
                ->has('candidates', 1)
                ->where('candidates.0.provider_key', 'netflix')
                ->where('candidates.0.action', 'create')
            );
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
