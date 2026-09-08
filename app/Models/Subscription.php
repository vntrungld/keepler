<?php

namespace App\Models;

use App\Support\CurrencyConverter;
use Illuminate\Database\Eloquent\Builder;
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
        'total_periods',
    ];

    protected $appends = ['subscribed_days', 'total_spent', 'periods_paid', 'periods_remaining', 'has_ended'];

    protected $casts = [
        'next_renewal_date' => 'date',
        'amount' => 'decimal:2',
        'amount_vnd' => 'integer',
        'is_trial' => 'boolean',
        'started_at' => 'date',
        'total_periods' => 'integer',
        'ends_at' => 'date',
        'last_reminder_sent_for' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (Subscription $subscription) {
            $subscription->amount_vnd = CurrencyConverter::toVnd(
                (float) $subscription->amount,
                $subscription->currency,
            );

            $subscription->ends_at = $subscription->computeEndsAt();
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

    /**
     * The date of the final billing occurrence, or null for an open-ended
     * subscription.
     *
     * `total_periods` counts every occurrence including the first, so a
     * 12-period installment that started on 5 Sep bills for the last time
     * 11 cycles later. Months are added without overflow so a 31 Jan start
     * lands on 28 Feb rather than rolling into March — the same clamp
     * calendar.js applies when it projects occurrences forward.
     */
    protected function computeEndsAt(): ?Carbon
    {
        if ($this->total_periods === null || $this->started_at === null) {
            return null;
        }

        $steps = $this->total_periods - 1;

        return $this->billing_cycle === 'yearly'
            ? $this->started_at->copy()->addYearsNoOverflow($steps)
            : $this->started_at->copy()->addMonthsNoOverflow($steps);
    }

    /**
     * Subscriptions that still have a billing ahead of them — everything
     * open-ended, plus fixed-term plans whose final billing has not passed.
     */
    public function scopeNotEnded(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->whereNull('ends_at')
            ->orWhere('ends_at', '>=', now()->toDateString()));
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

    /**
     * Whole billing cycles between two dates, counted the way calendar.js
     * counts steps: by calendar month/year, never by elapsed days, so a
     * day-of-month clamp can never shift the count by one.
     */
    protected function cyclesBetween(Carbon $from, Carbon $to): int
    {
        return $this->billing_cycle === 'yearly'
            ? $to->year - $from->year
            : ($to->year - $from->year) * 12 + ($to->month - $from->month);
    }

    /**
     * How many billings have already happened. The first one falls on
     * `started_at`, so this is simply the distance to the next one.
     */
    public function getPeriodsPaidAttribute(): ?int
    {
        if ($this->total_periods === null || $this->started_at === null) {
            return null;
        }

        return max(0, $this->cyclesBetween($this->started_at, $this->next_renewal_date));
    }

    /**
     * Billings still to come. Once the plan has ended nothing is left, even
     * though `next_renewal_date` stays parked on the final billing.
     */
    public function getPeriodsRemainingAttribute(): ?int
    {
        if ($this->periods_paid === null) {
            return null;
        }

        if ($this->has_ended) {
            return 0;
        }

        return max(0, $this->total_periods - $this->periods_paid);
    }

    /**
     * True once the final billing is behind us. Derived rather than stored so
     * it is right the moment it is read — there is no nightly job to miss.
     */
    public function getHasEndedAttribute(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isBefore(now()->startOfDay());
    }

    public function getSubscribedDaysAttribute(): int
    {
        return (int) $this->startedAtOrCreatedAt()->diffInDays(now());
    }

    public function getTotalSpentAttribute(): float
    {
        $cycleDays = $this->billing_cycle === 'yearly' ? 365 : 30;
        $completedCycles = intdiv(max(0, $this->subscribed_days), $cycleDays);

        if ($this->total_periods !== null) {
            $completedCycles = min($completedCycles, $this->total_periods);
        }

        return $completedCycles * (float) $this->amount;
    }
}
