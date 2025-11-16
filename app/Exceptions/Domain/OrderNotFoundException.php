<?php

namespace App\Exceptions\Domain;

use Exception;

class OrderNotFoundException extends Exception
{
    public function __construct(
        public readonly int $orderId,
        string $message = '',
    ) {
        $message = $message ?: "Order {$orderId} not found";
        parent::__construct($message);
    }
}
