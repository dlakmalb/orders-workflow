<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\KpiService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentCallbackJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $orderId,
        public bool $succeeded,
        public ?string $providerRef = null
    ) {}

    public function middleware(): array
    {
        return [new WithoutOverlapping("callback:order:{$this->orderId}")];
    }

    /**
     * Execute the job.
     */
    public function handle(KpiService $kpiService): void
    {
        $order = Order::find($this->orderId);

        if (! $order) {
            Log::warning("Order {$this->orderId} not found, skipping callback.");

            return;
        }

        if ($order->isTerminal()) {
            Log::info("Order {$this->orderId} is already in terminal state {$order->status}, skipping callback.");

            return;
        }

        if ($this->succeeded) {
            $this->handleSuccess($order);

            $kpiService->recordSuccess($order->customer_id, $order->total_cents);

            OrderProcessedNotification::dispatch($order, true);
        } else {
            $this->handleFailure($order);

            $kpiService->recordFailure($order->customer_id, $order->total_cents);

            OrderProcessedNotification::dispatch($order, false);
        }
    }

    private function handleSuccess(Order $order): void
    {
        DB::transaction(function () use ($order) {
            // Upsert a payment record
            Payment::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'provider' => 'fake',
                    'provider_ref' => $this->providerRef,
                    'amount_cents' => $order->total_cents,
                    'status' => 'SUCCEEDED',
                    'paid_at' => now(),
                ]
            );

            $order->update(['status' => Order::STATUS_PAID]);
        });
    }

    private function handleFailure(Order $order): void
    {
        DB::transaction(function () use ($order) {
            // Put stock back (rollback the reservation)
            $need = OrderItem::where('order_id', $order->id)
                ->select(['product_id', 'qty'])
                ->get()
                ->groupBy('product_id')
                ->map(fn ($rows) => $rows->sum('qty'));

            $products = Product::whereIn('id', $need->keys())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($need as $pid => $qty) {
                $products[$pid]?->increment('stock_qty', $qty);
            }

            // Record failed payment
            $this->recordPayment($order, 'FAILED', paidAt: null);

            $order->update(['status' => Order::STATUS_FAILED]);
        });
    }

    private function recordPayment(Order $order, string $status, ?Carbon $paidAt): void
    {
        Payment::updateOrCreate(
            ['order_id' => $order->id],
            [
                'provider' => 'fake',
                'provider_ref' => $this->providerRef,
                'amount_cents' => $order->total_cents,
                'status' => $status,
                'paid_at' => $paidAt,
            ]
        );
    }
}
