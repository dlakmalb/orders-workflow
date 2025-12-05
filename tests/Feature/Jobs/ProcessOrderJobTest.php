<?php

use App\Jobs\FakeGatewayChargeJob;
use App\Jobs\ProcessOrderJob;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Support\Facades\Bus;

function seedOrderWithStock(bool $enough = true): Order
{
    $customer = Customer::create([
        'external_id' => 'CUST-001',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $productA = Product::create([
        'sku' => 'PROD-A',
        'name' => 'Widget A',
        'price_cents' => 1299,
        'stock_qty' => $enough ? 10 : 0,
    ]);

    $productB = Product::create([
        'sku' => 'PROD-B',
        'name' => 'Widget B',
        'price_cents' => 500,
        'stock_qty' => 5,
    ]);

    $order = Order::create([
        'external_order_id' => 'ORD-1001',
        'customer_id' => $customer->id,
        'currency' => 'EUR',
        'status' => Order::STATUS_PENDING,
        'placed_at' => now(),
        'total_cents' => 3098,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $productA->id,
        'unit_price_cents' => 1299,
        'qty' => 2,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $productB->id,
        'unit_price_cents' => 500,
        'qty' => 1,
    ]);

    return $order;
}

it('reserves stock and dispatches FakeGatewayChargeJob when stock is sufficient', function () {
    // Arrange
    Bus::fake();

    $order = seedOrderWithStock(true);

    $stockService = app(StockService::class);

    // Act
    (new ProcessOrderJob($order->id))->handle($stockService);

    // Assert & next step should be scheduled
    Bus::assertDispatched(FakeGatewayChargeJob::class, function ($job) use ($order) {
        return $job->orderId === $order->id;
    });

    // stock decremented
    expect($order->orderItems()->sum('qty'))->toBe(3);
    expect(Product::where('sku', 'PROD-A')->value('stock_qty'))->toBe(8);
    expect(Product::where('sku', 'PROD-B')->value('stock_qty'))->toBe(4);
});

it('does not dispatch FakeGatewayChargeJob when stock is insufficient', function () {
    // Arrange
    Bus::fake();

    $order = seedOrderWithStock(false);

    $stockService = app(StockService::class);

    // Act
    (new ProcessOrderJob($order->id))->handle($stockService);

    // Assert
    Bus::assertNotDispatched(FakeGatewayChargeJob::class);

    // If your job marks FAILED immediately on reservation failure, assert it:
    expect($order->fresh()->status)->toBe(Order::STATUS_FAILED);
});
