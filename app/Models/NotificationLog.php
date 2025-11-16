<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends BaseModel
{
    protected $fillable = [
        'order_id',
        'customer_id',
        'channel',
        'status',
        'total_cents',
        'payload',
        'success',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'success' => 'boolean',
        'sent_at' => 'immutable_datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
