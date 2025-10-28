<?php

namespace App\Models;

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
}
