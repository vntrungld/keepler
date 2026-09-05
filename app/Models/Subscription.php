<?php

namespace App\Models;

use App\Support\CurrencyConverter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'provider_key',
        'amount',
        'currency',
        'billing_cycle',
        'next_renewal_date',
        'status',
        'cancel_url',
        'notes',
        'list',
        'category',
        'payment_method_id',
        'is_trial',
        'started_at',
    ];

    protected $appends = ['subscribed_days', 'total_spent'];

    protected $casts = [
        'next_renewal_date' => 'date',
        'amount' => 'decimal:2',
        'amount_vnd' => 'integer',
        'is_trial' => 'boolean',
        'started_at' => 'date',
        'last_reminder_sent_for' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (Subscription $subscription) {
            $subscription->amount_vnd = CurrencyConverter::toVnd(
                (float) $subscription->amount,
                $subscription->currency,
            );
        });

        static::created(function (Subscription $subscription) {
            $subscription->events()->create([
                'kind' => 'subscribed',
                'amount' => $subscription->amount,
                'currency' => $subscription->currency,
                'occurred_at' => $subscription->started_at?->toDateString() ?? now()->toDateString(),
            ]);
        });

        static::updated(function (Subscription $subscription) {
            if ($subscription->wasChanged('amount')) {
                $subscription->events()->create([
                    'kind' => 'price_changed',
                    'amount' => $subscription->amount,
                    'currency' => $subscription->currency,
                    'occurred_at' => now()->toDateString(),
                ]);
            }

            if ($subscription->wasChanged('status') && $subscription->status === 'cancelled') {
                $subscription->events()->create([
                    'kind' => 'cancelled',
                    'occurred_at' => now()->toDateString(),
                ]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    protected function startedAtOrCreatedAt(): Carbon
    {
        return $this->started_at ?? $this->created_at ?? now();
    }

    public function getSubscribedDaysAttribute(): int
    {
        return (int) $this->startedAtOrCreatedAt()->diffInDays(now());
    }

    public function getTotalSpentAttribute(): float
    {
        $cycleDays = $this->billing_cycle === 'yearly' ? 365 : 30;
        $completedCycles = intdiv(max(0, $this->subscribed_days), $cycleDays);

        return $completedCycles * (float) $this->amount;
    }
}
