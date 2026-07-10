<?php

namespace Tests\Feature\Gmail;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GmailConnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_connect(): void
    {
        $this->get('/gmail/connect')->assertRedirect(route('login'));
    }

    public function test_callback_stores_encrypted_tokens(): void
    {
        $user = User::factory()->create();

        $socialiteUser = (new SocialiteUser)->setRaw([]);
        $socialiteUser->token = 'access-abc';
        $socialiteUser->refreshToken = 'refresh-xyz';
        $socialiteUser->expiresIn = 3600;

        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialiteUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->actingAs($user)->get('/gmail/callback')->assertRedirect(route('dashboard'));

        $fresh = $user->fresh();
        $this->assertSame('access-abc', $fresh->gmail_access_token);
        $this->assertSame('refresh-xyz', $fresh->gmail_refresh_token);
        $this->assertTrue($fresh->hasGmailConnected());

        // Encrypted at rest.
        $raw = DB::table('users')->where('id', $user->id)->value('gmail_refresh_token');
        $this->assertNotSame('refresh-xyz', $raw);
    }

    public function test_disconnect_clears_tokens(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'gmail_access_token' => 'a', 'gmail_refresh_token' => 'r',
            'gmail_token_expires_at' => now()->addHour(),
        ])->save();

        $this->actingAs($user)->delete('/gmail/disconnect')->assertRedirect();

        $this->assertFalse($user->fresh()->hasGmailConnected());
        $this->assertNull($user->fresh()->gmail_access_token);
    }
}
