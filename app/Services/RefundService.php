<?php

namespace App\Services;

use App\Exceptions\Domain\InvalidRefundStateException;
use App\Exceptions\Domain\OrderNotFoundException;
use App\Exceptions\Domain\RefundAmountExceededException;
use App\Jobs\ProcessRefundJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefundService
{
    /**
     * Create a refund request.
     *
     * @param  int  $orderId
     * @param  int  $amountCents
     * @param  string|null  $reason
     * @param  string|null  $idempotencyKey
     * @return Refund
     *
     * @throws OrderNotFoundException
     * @throws RefundAmountExceededException
     */
    public function createRefund(
        int $orderId,
        int $amountCents,
        ?string $reason = null,
        ?string $idempotencyKey = null
    ): Refund {
        $order = Order::find($orderId);

        if (! $order) {
            throw new OrderNotFoundException($orderId);
        }

        // Check for duplicate refund request
        if ($idempotencyKey) {
            $existing = Refund::where('order_id', $orderId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                Log::info("Refund with idempotency key '{$idempotencyKey}' already exists for order {$orderId}");

                return $existing;
            }
        }

        // Calculate refundable amount
        $refundable = $this->calculateRefundableAmount($orderId);

        if ($amountCents > $refundable) {
            throw new RefundAmountExceededException($orderId, $amountCents, $refundable);
        }

        // Create refund
        $refund = Refund::create([
            'order_id' => $orderId,
            'amount_cents' => $amountCents,
            'reason' => $reason,
            'status' => Refund::STATUS_REQUESTED,
            'idempotency_key' => $idempotencyKey,
        ]);

        // Dispatch refund processing job
        ProcessRefundJob::dispatch($refund->id)->onQueue('refunds');

        Log::info("Refund {$refund->id} created for order {$orderId}", [
            'amount_cents' => $amountCents,
            'reason' => $reason,
        ]);

        return $refund;
    }

    /**
     * Process a refund.
     *
     * @param  int  $refundId
     * @return Refund
     *
     * @throws InvalidRefundStateException
     */
    public function processRefund(int $refundId, KpiService $kpiService): Refund
    {
        return DB::transaction(function () use ($refundId, $kpiService) {
            $refund = Refund::whereKey($refundId)
                ->lockForUpdate()
                ->first();

            if (! $refund || $refund->status !== Refund::STATUS_REQUESTED) {
                throw new InvalidRefundStateException($refundId, $refund?->status ?? 'NOT_FOUND');
            }

            $order = Order::find($refund->order_id);

            if (! $order) {
                $refund->update(['status' => Refund::STATUS_FAILED, 'processed_at' => now()]);
                Log::error("Refund {$refund->id} failed: order {$refund->order_id} not found.");

                return $refund;
            }

            // Validate refund amount
            $refundable = $this->calculateRefundableAmount($order->id);

            if ($refund->amount_cents > $refundable) {
                $refund->update(['status' => Refund::STATUS_FAILED, 'processed_at' => now()]);
                Log::error("Refund {$refund->id} failed: amount {$refund->amount_cents} exceeds refundable {$refundable}.");

                return $refund;
            }

            // Mark refund processed
            $refund->update([
                'status' => Refund::STATUS_PROCESSED,
                'processed_at' => now(),
            ]);

            // Update KPIs
            $kpiService->recordRefund($order->customer_id, $refund->amount_cents);

            Log::info("Refund {$refund->id} processed successfully");

            return $refund;
        });
    }

    /**
     * Calculate the refundable amount for an order.
     *
     * @param  int  $orderId
     * @return int
     */
    public function calculateRefundableAmount(int $orderId): int
    {
        $totalPaymentAmount = Payment::where('order_id', $orderId)
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->sum('amount_cents');

        $alreadyRefundedAmount = Refund::where('order_id', $orderId)
            ->whereIn('status', [Refund::STATUS_REQUESTED, Refund::STATUS_PROCESSED])
            ->sum('amount_cents');

        return max(0, $totalPaymentAmount - $alreadyRefundedAmount);
    }
}
