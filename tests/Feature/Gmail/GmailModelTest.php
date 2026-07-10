<?php

namespace Tests\Feature\Gmail;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GmailModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_gmail_tokens_are_encrypted_at_rest_and_hidden_from_array(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'gmail_access_token' => 'access-123',
            'gmail_refresh_token' => 'refresh-456',
            'gmail_token_expires_at' => now()->addHour(),
        ])->save();

        // Raw DB value is not the plaintext (encrypted at rest).
        $raw = DB::table('users')->where('id', $user->id)->value('gmail_refresh_token');
        $this->assertNotSame('refresh-456', $raw);

        // Cast decrypts on read.
        $this->assertSame('refresh-456', $user->fresh()->gmail_refresh_token);

        // Never leaks to serialized output (Inertia shares auth.user).
        $array = $user->fresh()->toArray();
        $this->assertArrayNotHasKey('gmail_access_token', $array);
        $this->assertArrayNotHasKey('gmail_refresh_token', $array);
    }

    public function test_has_gmail_connected_reflects_refresh_token_presence(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->hasGmailConnected());

        $user->forceFill(['gmail_refresh_token' => 'refresh-456'])->save();
        $this->assertTrue($user->fresh()->hasGmailConnected());
    }

    public function test_provider_key_is_mass_assignable_but_user_id_is_not(): void
    {
        $user = User::factory()->create();
        $sub = $user->subscriptions()->create([
            'name' => 'Netflix',
            'amount' => 9.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => now()->addDays(10)->toDateString(),
            'status' => 'active',
            'provider_key' => 'netflix',
        ]);
        $this->assertSame('netflix', $sub->provider_key);
        $this->assertSame($user->id, $sub->user_id);

        // user_id is guarded: a raw mass-assign attempt must not set it.
        $rogue = new Subscription(['user_id' => 999]);
        $this->assertNull($rogue->user_id);
    }
}
