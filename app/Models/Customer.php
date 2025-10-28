<?php

namespace App\Models;

class Customer extends BaseModel
{
    protected $fillable = ['external_id', 'email', 'name'];
}
