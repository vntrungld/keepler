<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_guests_visiting_root_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_authenticated_users_visiting_root_are_redirected_to_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    public function test_dashboard_renders_only_the_current_users_non_cancelled_subscriptions(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create(['name' => 'Netflix', 'status' => 'active']);
        Subscription::factory()->for($user)->create(['name' => 'Spotify', 'status' => 'pending_cancel']);
        Subscription::factory()->for($user)->create(['name' => 'Old', 'status' => 'cancelled']);

        $other = User::factory()->create();
        Subscription::factory()->for($other)->create(['name' => 'Theirs', 'status' => 'active']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('subscriptions', 2)
                ->where('subscriptions.0.name', 'Netflix')
                ->where('subscriptions.1.name', 'Spotify')
            );
    }

    public function test_each_subscription_prop_has_the_fields_the_orbit_needs(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('subscriptions.0', fn (Assert $sub) => $sub
                    ->hasAll(['id', 'name', 'amount', 'currency', 'amount_vnd', 'billing_cycle', 'next_renewal_date', 'status', 'list'])
                    ->etc()
                )
            );
    }

    public function test_dashboard_hides_subscriptions_whose_last_period_has_been_paid(): void
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
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('subscriptions', 1)
                ->where('subscriptions.0.name', 'Netflix')
            );
    }
}
