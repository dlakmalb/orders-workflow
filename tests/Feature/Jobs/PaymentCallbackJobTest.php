<?php

use App\Jobs\OrderProcessedNotification;
use App\Jobs\PaymentCallbackJob;
use App\Models\Customer;
use App\Models\Order;
use App\Services\KpiService;
use Illuminate\Support\Facades\Bus;
use Mockery as M;

afterEach(function () {
    M::close();
});

it('marks order PAID on success, updates KPIs, and dispatches notification', function () {
    // Arrange
    Bus::fake();

    $customer = Customer::create([
        'external_id' => 'CUST-001',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $order = Order::create([
        'external_order_id' => 'ORD-1001',
        'customer_id' => $customer->id,
        'currency' => 'EUR',
        'status' => Order::STATUS_PENDING,
        'placed_at' => now(),
        'total_cents' => 3000,
    ]);

    // mock KpiService so we don't hit Redis
    $kpis = M::mock(KpiService::class);
    $kpis->shouldReceive('recordSuccess')->once()->with($customer->id, 3000);

    app()->instance(KpiService::class, $kpis);

    // Act
    (new PaymentCallbackJob(orderId: $order->id, succeeded: true, providerRef: 'FAKE-1'))
        ->handle($kpis);

    // Assert
    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);

    Bus::assertDispatched(OrderProcessedNotification::class, function ($job) use ($order) {
        return $job->orderId === $order->id && $job->succeeded === true;
    });
});

it('marks order FAILED on failure, updates KPIs (failure), and dispatches notification', function () {
    // Arrange
    Bus::fake();

    $customer = Customer::create([
        'external_id' => 'CUST-001',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $order = Order::create([
        'external_order_id' => 'ORD-1001',
        'customer_id' => $customer->id,
        'currency' => 'EUR',
        'status' => Order::STATUS_PENDING,
        'placed_at' => now(),
        'total_cents' => 3000,
    ]);

    $kpis = Mockery::mock(KpiService::class);
    $kpis->shouldReceive('recordFailure')->once()->with($customer->id, 3000);
    app()->instance(KpiService::class, $kpis);

    // Act
    (new PaymentCallbackJob(orderId: $order->id, succeeded: false, providerRef: 'FAKE-2'))
        ->handle($kpis);

    // Assert
    expect($order->fresh()->status)->toBe(Order::STATUS_FAILED);

    Bus::assertDispatched(
        OrderProcessedNotification::class,
        fn ($job) => $job->orderId === $order->id && $job->succeeded === false
    );
});
