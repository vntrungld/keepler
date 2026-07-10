<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmailScan extends Model
{
    protected $fillable = [
        'status',
        'total',
        'processed',
        'candidates',
        'error',
    ];

    protected $casts = [
        'candidates' => 'array',
    ];

    protected $attributes = [
        'total' => 0,
        'processed' => 0,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function progressPercent(): int
    {
        return $this->total > 0
            ? (int) round($this->processed / $this->total * 100)
            : 0;
    }
}
