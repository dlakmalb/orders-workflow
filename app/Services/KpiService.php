<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

class KpiService
{
    private function kpiKey(string $metric): string
    {
        return 'kpi:'.Carbon::now()->format('Y-m-d').':'.$metric;
    }

    private function leaderboardKey(): string
    {
        return 'leaderboard:customers';
    }

    /**
     * Record a successful payment - increments revenue, order count, updates AOV and leaderboard.
     */
    public function recordSuccess(int $customerId, int $amountCents): void
    {
        Redis::incrby($this->kpiKey('revenue_cents'), $amountCents);
        Redis::incr($this->kpiKey('order_count'));

        $this->updateAverageOrderValue();

        Redis::zincrby($this->leaderboardKey(), $amountCents, (string) $customerId);
    }

    /**
     * Record a payment failure - only increments failed count, no revenue changes.
     * Revenue is never added for failures, so nothing to decrement.
     */
    public function recordFailure(int $customerId, int $amountCents): void
    {
        Redis::incr($this->kpiKey('failed_order_count'));
        // Note: We do NOT decrement revenue since it was never added
    }

    /**
     * Record a refund - decrements revenue, updates AOV, and adjusts leaderboard.
     */
    public function recordRefund(int $customerId, int $amountCents): void
    {
        // daily revenue goes down
        Redis::decrby($this->kpiKey('revenue_cents'), $amountCents);
        Redis::incr($this->kpiKey('refund_count'));
        Redis::incrby($this->kpiKey('refund_amount_cents'), $amountCents);

        $this->updateAverageOrderValue();

        // leaderboard score goes down
        Redis::zincrby($this->leaderboardKey(), -$amountCents, (string) $customerId);
    }

    /**
     * Update the average order value based on current revenue and order count.
     */
    private function updateAverageOrderValue(): void
    {
        $revenue = (int) Redis::get($this->kpiKey('revenue_cents'));
        $count = (int) Redis::get($this->kpiKey('order_count'));

        if ($count > 0) {
            Redis::set($this->kpiKey('avg_order_value_cents'), (int) floor($revenue / $count));
        }
    }
}
