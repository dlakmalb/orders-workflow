<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class OrderProcessedNotification implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $orderId,
        public bool $succeeded
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $order = Order::with('customer')->find($this->orderId);

        if (! $order) {
            Log::warning("Order {$this->orderId} not found, skipping notification process.");

            return;
        }

        $payload = [
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'status' => $this->succeeded ? 'PAID' : 'FAILED',
            'total_cents' => $order->total_cents,
        ];

        Log::info('Order processed notification', $payload);
    }
}
