<?php

namespace App\Exceptions\Domain;

use Exception;

class InsufficientStockException extends Exception
{
    public function __construct(
        public readonly int $productId,
        public readonly int $requested,
        public readonly int $available,
        string $message = '',
    ) {
        $message = $message ?: "Insufficient stock for product {$productId}. Requested: {$requested}, Available: {$available}";
        parent::__construct($message);
    }
}
