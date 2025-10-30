<?php

use App\Jobs\OrderProcessedNotification;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

it('creates a notification log entry', function () {
    // Arrange
    Log::spy();

    $customer = Customer::create([
        'external_id' => 'CUST-001',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $order = Order::create([
        'external_order_id' => 'ORD-1001',
        'customer_id' => $customer->id,
        'currency' => 'EUR',
        'status' => Order::STATUS_PAID,
        'placed_at' => now(),
        'total_cents' => 4200,
    ]);

    // Act
    (new OrderProcessedNotification(orderId: $order->id, succeeded: true))->handle();

    // Assert
    expect(NotificationLog::count())->toBe(1);

    $row = NotificationLog::first();

    expect($row->order_id)->toBe($order->id);
    expect($row->status)->toBe(Order::STATUS_PAID);
    expect($row->total_cents)->toBe(4200);

    Log::shouldHaveReceived('info')->once();
});
