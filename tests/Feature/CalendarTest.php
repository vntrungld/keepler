<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/calendar')->assertRedirect(route('login'));
    }

    public function test_calendar_renders_only_the_current_users_non_cancelled_subscriptions(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create(['name' => 'Netflix', 'status' => 'active']);
        Subscription::factory()->for($user)->create(['name' => 'Old', 'status' => 'cancelled']);

        $other = User::factory()->create();
        Subscription::factory()->for($other)->create(['name' => 'Theirs', 'status' => 'active']);

        $this->actingAs($user)
            ->get('/calendar')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Calendar/Index', false)
                ->has('subscriptions', 1)
                ->where('subscriptions.0.name', 'Netflix')
            );
    }
}
