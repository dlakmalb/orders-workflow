<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
    public function handle(): void
    {
        $order = Order::findorFail($this->orderId);

        if (! $order) {
            return;
        }

        if ($order->isTerminal()) {
            Log::info("Order {$order->id} is already in terminal state {$order->status}, skipping processing.");

            return;
        }

        if (! $this->reserveStock($order)) {
            $order->update(['status' => Order::STATUS_FAILED]);

            return;
        }

        FakeGatewayChargeJob::dispatch(orderId: $order->id)
            ->delay(now()->addSeconds(2));
    }

    /**
     * Try to reserve stock for all items in the order atomically.
     * Returns true on success, false if reservation not possible.
     */
    private function reserveStock(Order $order): bool
    {
        // Load items with the product ids and quantities
        $items = OrderItem::where('order_id', $order->id)
            ->select(['product_id', 'qty'])
            ->get();

        if ($items->isEmpty()) {
            Log::warning("Order {$order->id}: no items to reserve.");

            return false;
        }

        // Build a map product_id => needed qty
        $needed = $items->groupBy('product_id')
            ->map(fn ($group) => (int) $group->sum('qty'));

        // Acquire distinct locks for all products needed.
        $locks = [];

        try {
            foreach ($needed->keys() as $pid) {
                $lock = Cache::lock("stock:product:{$pid}", 5);

                // Try up to 2s to acquire; if fails, return false gracefully
                if (! $lock->block(2)) {
                    Log::warning("Order {$order->id}: timeout waiting for stock lock product {$pid}.");

                    return false;
                }

                $locks[] = $lock;
            }

            // With locks held, do a single transaction to check & decrement stock
            return DB::transaction(function () use ($needed): bool {
                // Check availability
                $products = Product::whereIn('id', $needed->keys())
                    ->lockForUpdate() // row-level lock in MySQL
                    ->get()
                    ->keyBy('id');

                // Validate all first
                $insufficient = $needed->first(
                    fn ($qty, $pid) => ! isset($products[$pid]) || $products[$pid]->stock_qty < $qty
                );

                if ($insufficient !== null) {
                    return false; // any shortage → abort with no changes
                }

                // Decrement stock
                foreach ($needed as $pid => $qty) {
                    $products[$pid]->decrement('stock_qty', $qty);
                }

                return true;
            });
        } catch (\Throwable $e) {
            Log::error("Order {$order->id}: reserveStock error: {$e->getMessage()}");

            return false;
        } finally {
            // Always release locks
            foreach ($locks as $lock) {
                try {
                    $lock?->release();
                } catch (\Throwable) {
                }
            }
        }
    }
}
