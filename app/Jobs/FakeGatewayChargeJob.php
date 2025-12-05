<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class FakeGatewayChargeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $orderId) {}

    public function middleware(): array
    {
        return [new WithoutOverlapping("charge:order:{$this->orderId}")];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $order = Order::find($this->orderId);

        if (! $order) {
            Log::warning("Order {$this->orderId} not found, skipping processing.");

            return;
        }

        if ($order->isTerminal()) {
            Log::info("Order {$this->orderId} is already in terminal state {$order->status}, skipping processing.");

            return;
        }

        $success = ($order->total_cents % 2) === 0;

        Log::info("Fake payment gateway processing order {$order->id}", [
            'order_id' => $order->id,
            'amount_cents' => $order->total_cents,
            'success' => $success,
        ]);

        PaymentCallbackJob::dispatch(
            orderId: $order->id,
            succeeded: $success,
            providerRef: 'FAKE-'.uniqid()
        );
    }
}
