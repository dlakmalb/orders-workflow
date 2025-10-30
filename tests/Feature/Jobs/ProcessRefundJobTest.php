<?php

use App\Jobs\ProcessRefundJob;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\KpiService;
use Mockery as M;

it('processes a partial refund, updates KPIs, and marks refund PROCESSED', function () {
    // Arrange
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
        'total_cents' => 10000,
    ]);

    Payment::create([
        'order_id' => $order->id,
        'amount_cents' => 10000,
        'currency' => 'EUR',
        'status' => Payment::STATUS_SUCCEEDED,
        'provider_ref' => 'GW-PAY-001',
        'processed_at' => now(),
    ]);

    $refund = Refund::create([
        'order_id' => $order->id,
        'amount_cents' => 2500,
        'status' => Refund::STATUS_REQUESTED,
        'idempotency_key' => 'GW-REF-001',
    ]);

    $kpis = M::mock(KpiService::class);
    $kpis->shouldReceive('recordRefund')->once()->with($customer->id, 2500);
    app()->instance(KpiService::class, $kpis);

    // Act
    (new ProcessRefundJob($refund->id))->handle($kpis);

    // Assert
    expect($refund->fresh()->status)->toBe(Refund::STATUS_PROCESSED);
    expect($refund->fresh()->processed_at)->not->toBeNull();
});

it('fails invalid refund amounts', function () {
    // Arrange
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
        'total_cents' => 1000,
    ]);

    Payment::create([
        'order_id' => $order->id,
        'amount_cents' => 750,
        'currency' => 'EUR',
        'status' => Payment::STATUS_SUCCEEDED,
        'provider_ref' => 'GW-PAY-001',
        'processed_at' => now(),
    ]);

    $refund = Refund::create([
        'order_id' => $order->id,
        'amount_cents' => 5000,
        'status' => Refund::STATUS_REQUESTED,
    ]);

    $kpis = M::mock(KpiService::class);
    app()->instance(KpiService::class, $kpis);

    // Act
    (new ProcessRefundJob($refund->id))->handle($kpis);

    // Assert
    expect($refund->fresh()->status)->toBe(Refund::STATUS_FAILED);
});
