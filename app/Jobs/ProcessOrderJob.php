<?php

namespace App\Jobs;

use App\Exceptions\Domain\InsufficientStockException;
use App\Models\Order;
use App\Services\StockService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class ProcessOrderJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $backoff = 5; // seconds between retries

    /**
     * Create a new job instance.
     */
    public function __construct(public int $orderId) {}

    // Prevent two ProcessOrderJob for the same order from running at once
    public function middleware(): array
    {
        return [new WithoutOverlapping("order:{$this->orderId}", 15)];
    }

    /**
     * Execute the job.
     */
    public function handle(StockService $stockService): void
    {
        $order = Order::findOrFail($this->orderId);

        if ($order->isTerminal()) {
            Log::info("Order {$this->orderId} is already in terminal state {$order->status}, skipping order process.");

            return;
        }

        try {
            $reserved = $stockService->reserveForOrder($order);

            if (! $reserved) {
                $order->update(['status' => Order::STATUS_FAILED]);
                Log::warning("Order {$this->orderId} failed due to stock reservation failure.");

                return;
            }

            $delay = config('services.fake_payment.delay_seconds', 2);

            FakeGatewayChargeJob::dispatch(orderId: $order->id)
                ->delay(now()->addSeconds($delay));

            Log::info("Order {$this->orderId} stock reserved, payment processing queued.");
        } catch (InsufficientStockException $e) {
            $order->update(['status' => Order::STATUS_FAILED]);
            Log::error("Order {$this->orderId} failed: {$e->getMessage()}");
        }
    }

}
