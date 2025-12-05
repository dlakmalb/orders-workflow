<?php

namespace App\Services;

use App\Exceptions\Domain\OrderAlreadyProcessedException;
use App\Exceptions\Domain\OrderNotFoundException;
use App\Jobs\ProcessOrderJob;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    /**
     * Create an order from CSV import data.
     *
     * @param  array  $data
     * @return Order
     */
    public function createFromImport(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $customer = $this->upsertCustomer(
                $data['customer_id'],
                $data['customer_email'],
                $data['customer_name']
            );

            $product = $this->upsertProduct(
                $data['product_sku'],
                $data['product_name'],
                $data['unit_price_cents']
            );

            $order = $this->upsertOrder(
                $data['external_order_id'],
                $customer->id,
                $data['currency'],
                CarbonImmutable::parse($data['order_placed_at'])
            );

            $this->addOrderItem(
                $order->id,
                $product->id,
                $data['unit_price_cents'],
                $data['qty']
            );

            return $order;
        });
    }

    /**
     * Calculate and update order total.
     *
     * @param  int  $orderId
     * @return Order
     */
    public function calculateTotal(int $orderId): Order
    {
        $order = Order::findOrFail($orderId);

        $total = OrderItem::where('order_id', $orderId)
            ->selectRaw('SUM(subtotal_cents) as total')
            ->value('total');

        $order->update(['total_cents' => (int) ($total ?? 0)]);

        return $order->fresh();
    }

    /**
     * Dispatch job to process the order.
     *
     * @param  int  $orderId
     * @return void
     */
    public function processOrder(int $orderId): void
    {
        ProcessOrderJob::dispatch($orderId);
    }

    /**
     * Mark order as paid.
     *
     * @param  int  $orderId
     * @return Order
     *
     * @throws OrderNotFoundException
     * @throws OrderAlreadyProcessedException
     */
    public function markAsPaid(int $orderId): Order
    {
        $order = Order::find($orderId);

        if (! $order) {
            throw new OrderNotFoundException($orderId);
        }

        if ($order->isTerminal()) {
            throw new OrderAlreadyProcessedException($orderId, $order->status);
        }

        $order->update(['status' => Order::STATUS_PAID]);

        Log::info("Order {$orderId} marked as PAID");

        return $order->fresh();
    }

    /**
     * Mark order as failed.
     *
     * @param  int  $orderId
     * @return Order
     *
     * @throws OrderNotFoundException
     */
    public function markAsFailed(int $orderId): Order
    {
        $order = Order::find($orderId);

        if (! $order) {
            throw new OrderNotFoundException($orderId);
        }

        $order->update(['status' => Order::STATUS_FAILED]);

        Log::warning("Order {$orderId} marked as FAILED");

        return $order->fresh();
    }

    /**
     * Upsert a customer by external ID.
     */
    private function upsertCustomer(string $externalId, string $email, string $name): Customer
    {
        return Customer::updateOrCreate(
            ['external_id' => $externalId],
            ['email' => $email, 'name' => $name]
        );
    }

    /**
     * Upsert a product by SKU.
     */
    private function upsertProduct(string $sku, string $name, int $priceCents): Product
    {
        return Product::updateOrCreate(
            ['sku' => $sku],
            [
                'name' => $name,
                'price_cents' => $priceCents,
                'stock_qty' => config('app.default_product_stock', 50),
            ]
        );
    }

    /**
     * Upsert an order by external order ID.
     */
    private function upsertOrder(
        string $externalOrderId,
        int $customerId,
        string $currency,
        CarbonImmutable $placedAt
    ): Order {
        return Order::updateOrCreate(
            ['external_order_id' => $externalOrderId],
            [
                'customer_id' => $customerId,
                'currency' => $currency,
                'placed_at' => $placedAt,
                'status' => Order::STATUS_PENDING,
            ]
        );
    }

    /**
     * Add an order item.
     */
    private function addOrderItem(
        int $orderId,
        int $productId,
        int $unitPriceCents,
        int $qty
    ): OrderItem {
        return OrderItem::create([
            'order_id' => $orderId,
            'product_id' => $productId,
            'unit_price_cents' => $unitPriceCents,
            'qty' => $qty,
        ]);
    }
}
