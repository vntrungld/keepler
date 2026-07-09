<?php

namespace Database\Seeders;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'demo@orbit.test'],
            ['name' => 'Orbit Demo', 'google_id' => 'demo-google'],
        );

        $samples = [
            ['name' => 'Netflix', 'amount' => 260000, 'currency' => 'VND', 'billing_cycle' => 'monthly', 'days' => 5, 'status' => 'active'],
            ['name' => 'Spotify', 'amount' => 59000, 'currency' => 'VND', 'billing_cycle' => 'monthly', 'days' => 20, 'status' => 'active'],
            ['name' => 'ChatGPT Plus', 'amount' => 20, 'currency' => 'USD', 'billing_cycle' => 'monthly', 'days' => 12, 'status' => 'pending_cancel'],
            ['name' => 'Adobe Creative Cloud', 'amount' => 599.88, 'currency' => 'USD', 'billing_cycle' => 'yearly', 'days' => 200, 'status' => 'active'],
            ['name' => 'YouTube Premium', 'amount' => 79000, 'currency' => 'VND', 'billing_cycle' => 'monthly', 'days' => 1, 'status' => 'cancelled'],
        ];

        foreach ($samples as $s) {
            Subscription::updateOrCreate(
                ['user_id' => $user->id, 'name' => $s['name']],
                [
                    'amount' => $s['amount'],
                    'currency' => $s['currency'],
                    'billing_cycle' => $s['billing_cycle'],
                    'next_renewal_date' => now()->addDays($s['days'])->toDateString(),
                    'status' => $s['status'],
                ],
            );
        }
    }
}
