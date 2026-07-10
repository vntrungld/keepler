<?php

namespace App\Models;

use App\Support\CurrencyConverter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected $casts = [
        'next_renewal_date' => 'date',
        'amount' => 'decimal:2',
        'amount_vnd' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (Subscription $subscription) {
            $subscription->amount_vnd = CurrencyConverter::toVnd(
                (float) $subscription->amount,
                $subscription->currency,
            );
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
