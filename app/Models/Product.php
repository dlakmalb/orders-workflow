<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends BaseModel
{
    protected $fillable = ['sku', 'name', 'price_cents', 'stock_qty'];

    protected $casts = [
        'price_cents' => 'integer',
        'stock_qty' => 'integer',
    ];

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
