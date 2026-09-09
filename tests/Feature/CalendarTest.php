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
                ->component('Calendar/Index')
                ->has('subscriptions', 1)
                ->where('subscriptions.0.name', 'Netflix')
            );
    }

    public function test_calendar_hides_subscriptions_whose_last_period_has_been_paid(): void
    {
        $user = User::factory()->create();
        $this->travelTo('2027-09-06');

        Subscription::factory()->for($user)->create([
            'name' => 'Netflix',
            'started_at' => '2026-09-05',
            'next_renewal_date' => '2026-10-05',
        ]);
        Subscription::factory()->for($user)->create([
            'name' => 'Macbook tra gop',
            'started_at' => '2026-09-05',
            'next_renewal_date' => '2027-08-05',
            'total_periods' => 12,
        ]);

        $this->actingAs($user)
            ->get('/calendar')
            ->assertInertia(fn (Assert $page) => $page
                ->has('subscriptions', 1)
                ->where('subscriptions.0.name', 'Netflix')
            );
    }
}
