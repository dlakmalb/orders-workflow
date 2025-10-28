<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends BaseModel
{
    protected $fillable = [
        'order_id',
        'provider',
        'provider_ref',
        'amount_cents',
        'status', // 'SUCCEEDED' | 'FAILED'
        'paid_at',
    ];

    protected $casts = [
        'paid_at' => 'immutable_datetime',
    ];

    // Used status constants to avoid string literals everywhere
    public const STATUS_SUCCEEDED = 'SUCCEEDED';

    public const STATUS_FAILED = 'FAILED';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
