<?php

namespace App\Models;

class OrderItem extends BaseModel
{
    protected $fillable = ['order_id', 'product_id', 'unit_price_cents', 'qty'];
}
