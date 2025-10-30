<?php

namespace App\Console\Commands;

use App\Jobs\ProcessRefundJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Console\Command;

class RefundOrderCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:refund {order_id} {amount_cents} {--key=} {--reason=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Request a partial or full refund for an order';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $orderId = (int) $this->argument('order_id');
        $amount = (int) $this->argument('amount_cents');
        $key = $this->option('key');
        $reason = $this->option('reason');

        $order = Order::find($orderId);

        if (! $order) {
            $this->error("Order {$orderId} not found.");

            return self::FAILURE;
        }

        if (! in_array($order->status, [Order::STATUS_PAID], true)) {
            $this->error("Order {$order->id} is not refundable (status: {$order->status}).");

            return self::FAILURE;
        }

        if ($key) {
            $exists = Refund::where('order_id', $order->id)
                ->where('idempotency_key', $key)
                ->exists();

            if ($exists) {
                $this->info("Refund with key '{$key}' already exists for order {$order->id}; no action taken.");

                return self::SUCCESS;
            }
        }

        $totalPaymentAmount = Payment::where('order_id', $order->id)
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->sum('amount_cents');

        $alreadyRefundedAmount = Refund::where('order_id', $order->id)
            ->whereIn('status', [Refund::STATUS_REQUESTED, Refund::STATUS_PROCESSED])
            ->sum('amount_cents');

        $refundable = max(0, $totalPaymentAmount - $alreadyRefundedAmount);

        if ($refundable < 1) {
            $this->error("Order {$order->id} has no refundable balance.");

            return self::FAILURE;
        }

        if ($amount < 1 || $amount > $refundable) {
            $this->error("Invalid amount. Must be between 1 and {$refundable}.");

            return self::FAILURE;
        }

        $refund = Refund::create([
            'order_id' => $order->id,
            'amount_cents' => $amount,
            'reason' => $reason,
            'status' => Refund::STATUS_REQUESTED,
            'idempotency_key' => $key,
        ]);

        ProcessRefundJob::dispatch($refund->id)->onQueue('refunds');

        $this->info("Refund {$refund->id} queued for order {$order->id} (amount {$amount}).");

        return self::SUCCESS;
    }
}
