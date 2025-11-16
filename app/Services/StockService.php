<?php

namespace App\Services;

use App\Exceptions\Domain\InsufficientStockException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockService
{
    /**
     * Reserve stock for an order atomically.
     *
     * @param  Order  $order
     * @return bool
     *
     * @throws InsufficientStockException
     */
    public function reserveForOrder(Order $order): bool
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

        return $this->reserveStock($needed, "order:{$order->id}");
    }

    /**
     * Restore stock for a failed order.
     *
     * @param  Order  $order
     * @return void
     */
    public function restoreForOrder(Order $order): void
    {
        $need = OrderItem::where('order_id', $order->id)
            ->select(['product_id', 'qty'])
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->sum('qty'));

        DB::transaction(function () use ($need) {
            $products = Product::whereIn('id', $need->keys())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($need as $pid => $qty) {
                $products[$pid]?->increment('stock_qty', $qty);
            }
        });

        Log::info('Stock restored', ['quantities' => $need->toArray()]);
    }

    /**
     * Reserve stock with distributed locks.
     *
     * @param  Collection  $needed  Map of product_id => qty
     * @param  string  $context
     * @return bool
     *
     * @throws InsufficientStockException
     */
    private function reserveStock(Collection $needed, string $context): bool
    {
        $locks = [];

        try {
            // Acquire distinct locks for all products needed
            foreach ($needed->keys() as $pid) {
                $lock = Cache::lock("stock:product:{$pid}", 5);

                // Try up to 2s to acquire; if fails, return false gracefully
                if (! $lock->block(2)) {
                    Log::warning("{$context}: timeout waiting for stock lock product {$pid}.");

                    return false;
                }

                $locks[] = $lock;
            }

            // With locks held, do a single transaction to check & decrement stock
            return DB::transaction(function () use ($needed, $context): bool {
                // Check availability
                $products = Product::whereIn('id', $needed->keys())
                    ->lockForUpdate() // row-level lock in MySQL
                    ->get()
                    ->keyBy('id');

                // Validate all first
                foreach ($needed as $pid => $qty) {
                    if (! isset($products[$pid])) {
                        Log::error("{$context}: product {$pid} not found");

                        return false;
                    }

                    if ($products[$pid]->stock_qty < $qty) {
                        $available = $products[$pid]->stock_qty;
                        Log::error("{$context}: insufficient stock for product {$pid}. Requested: {$qty}, Available: {$available}");

                        throw new InsufficientStockException($pid, $qty, $available);
                    }
                }

                // Decrement stock
                foreach ($needed as $pid => $qty) {
                    $products[$pid]->decrement('stock_qty', $qty);
                }

                Log::info("{$context}: stock reserved", ['quantities' => $needed->toArray()]);

                return true;
            });
        } catch (InsufficientStockException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("{$context}: reserveStock error: {$e->getMessage()}");

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
