<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        config()->set('currency.rates', ['VND' => 1, 'USD' => 26000]);
    }

    public function test_seeder_creates_a_demo_user_with_sample_subscriptions(): void
    {
        $this->seed();

        $user = User::where('email', 'demo@orbit.test')->first();
        $this->assertNotNull($user);
        $this->assertGreaterThanOrEqual(4, Subscription::where('user_id', $user->id)->count());
    }
}
