<?php

namespace App\Exceptions\Domain;

use Exception;

class RefundAmountExceededException extends Exception
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $requestedAmount,
        public readonly int $refundableAmount,
        string $message = '',
    ) {
        $message = $message ?: "Refund amount {$requestedAmount} exceeds refundable amount {$refundableAmount} for order {$orderId}";
        parent::__construct($message);
    }
}
