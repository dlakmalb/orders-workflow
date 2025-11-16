<?php

namespace App\Exceptions\Domain;

use Exception;

class OrderAlreadyProcessedException extends Exception
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $status,
        string $message = '',
    ) {
        $message = $message ?: "Order {$orderId} is already in terminal state: {$status}";
        parent::__construct($message);
    }
}
