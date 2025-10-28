<?php

namespace App\Models;

class Order extends BaseModel
{
    protected $fillable = [
        'external_order_id',
        'customer_id',
        'status',
        'currency',
        'total_cents',
        'placed_at',
    ];

    protected $casts = [
        'placed_at' => 'immutable_datetime',
    ];
}
