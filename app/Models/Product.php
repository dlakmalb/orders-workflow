<?php

namespace App\Models;

class Product extends BaseModel
{
    protected $fillable = ['sku', 'name', 'price_cents', 'stock_qty'];
}
