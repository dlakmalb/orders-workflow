<?php

namespace App\Exceptions\Domain;

use Exception;

class InvalidRefundStateException extends Exception
{
    public function __construct(
        public readonly int $refundId,
        public readonly string $currentState,
        string $message = '',
    ) {
        $message = $message ?: "Refund {$refundId} is in invalid state: {$currentState}";
        parent::__construct($message);
    }
}
