<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\KpiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessRefundJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $refundId)
    {
        $this->onQueue('refunds');
    }

    public function middleware(): array
    {
        // Prevent two workers processing the same refund simultaneously.
        return [new WithoutOverlapping("refund:{$this->refundId}")];
    }

    /**
     * Execute the job.
     */
    public function handle(KpiService $kpiService): void
    {
        DB::transaction(function () use ($kpiService) {
            $refund = Refund::whereKey($this->refundId)
                ->lockForUpdate()
                ->first();

            if (! $refund || $refund->status !== Refund::STATUS_REQUESTED) {
                Log::warning("Refund {$this->refundId} not found or not in REQUESTED status.");

                return;
            }

            $order = Order::find($refund->order_id);

            if (! $order) {
                $refund->update(['status' => Refund::STATUS_FAILED, 'processed_at' => now()]);
                Log::error("Refund {$refund->id} failed: order {$refund->order_id} not found.");

                return;
            }

            $amount = (int) $refund->amount_cents;

            if ($amount < 1) {
                $refund->update(['status' => Refund::STATUS_FAILED, 'processed_at' => now()]);
                Log::error("Invalid refund amount {$amount} for refund {$this->refundId} on order {$order->id}.");

                return;
            }

            $totalPaymentAmount = Payment::where('order_id', $order->id)
                ->where('status', Payment::STATUS_SUCCEEDED)
                ->sum('amount_cents');

            $alreadyRefundedAmount = Refund::where('order_id', $order->id)
                ->whereIn('status', [Refund::STATUS_REQUESTED, Refund::STATUS_PROCESSED])
                ->sum('amount_cents');

            $refundable = max(0, $totalPaymentAmount - $alreadyRefundedAmount);

            if ($amount > $refundable) {
                $refund->update(['status' => Refund::STATUS_FAILED, 'processed_at' => now()]);
                Log::error("Refund {$refund->id} failed: amount {$amount} exceeds refundable {$refundable}.");

                return;
            }

            // Mark refund processed
            $refund->update([
                'status' => Refund::STATUS_PROCESSED,
                'processed_at' => now(),
            ]);

            // Update KPIs/leaderboard first (real-time effect)
            $kpiService->recordRefund($order->customer_id, $amount);
        });
    }
}
