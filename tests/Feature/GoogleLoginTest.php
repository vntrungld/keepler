<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(string $id, string $email, string $name = 'Trung'): SocialiteUser
    {
        $user = new SocialiteUser;
        $user->map([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'avatar' => 'https://example.com/avatar.png',
        ]);

        return $user;
    }

    public function test_callback_creates_a_new_user_and_authenticates(): void
    {
        Socialite::shouldReceive('driver->user')
            ->andReturn($this->fakeGoogleUser('google-123', 'trung@example.com'));

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'google_id' => 'google-123',
            'email' => 'trung@example.com',
        ]);
        $this->assertSame(1, User::count());
        $this->assertNotNull(User::first()->email_verified_at);
        $this->assertTrue(User::first()->hasVerifiedEmail());
    }

    public function test_callback_with_existing_google_id_does_not_duplicate_user(): void
    {
        User::factory()->create([
            'google_id' => 'google-123',
            'email' => 'trung@example.com',
        ]);

        Socialite::shouldReceive('driver->user')
            ->andReturn($this->fakeGoogleUser('google-123', 'trung@example.com'));

        $this->get('/auth/google/callback');

        $this->assertSame(1, User::count());
        $this->assertAuthenticated();
    }

    public function test_new_google_user_can_reach_dashboard(): void
    {
        Socialite::shouldReceive('driver->user')
            ->andReturn($this->fakeGoogleUser('google-456', 'newuser@example.com'));

        $this->get('/auth/google/callback');

        $response = $this->get('/dashboard');

        $response->assertOk();
    }
}
