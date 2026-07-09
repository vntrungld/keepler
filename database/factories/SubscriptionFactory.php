<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Netflix', 'Spotify', 'ChatGPT', 'Adobe']),
            'amount' => 9.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_renewal_date' => now()->addDays(10)->toDateString(),
            'status' => 'active',
        ];
    }
}
