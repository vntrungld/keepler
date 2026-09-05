<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Notifications\SubscriptionRenewalReminder;
use Illuminate\Console\Command;

class SendRenewalReminders extends Command
{
    protected $signature = 'subscriptions:send-renewal-reminders';

    protected $description = 'Gửi email nhắc nhở cho các subscription sắp đến hạn gia hạn';

    public function handle(): int
    {
        $today = now()->toDateString();

        Subscription::query()
            ->where('status', 'active')
            ->whereHas('user', function ($query) {
                $query->where('renewal_reminders_enabled', true)
                    ->whereNotNull('email_verified_at');
            })
            ->with('user')
            ->chunkById(100, function ($subscriptions) use ($today) {
                foreach ($subscriptions as $subscription) {
                    $targetDate = $subscription->next_renewal_date
                        ->copy()
                        ->subDays($subscription->user->reminder_days_before)
                        ->toDateString();

                    if ($targetDate !== $today) {
                        continue;
                    }

                    if ($subscription->last_reminder_sent_for?->toDateString() === $subscription->next_renewal_date->toDateString()) {
                        continue;
                    }

                    $subscription->user->notify(new SubscriptionRenewalReminder($subscription));
                    $subscription->forceFill(['last_reminder_sent_for' => $subscription->next_renewal_date])->save();
                }
            });

        return self::SUCCESS;
    }
}
