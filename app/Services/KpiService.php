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

    public function recordSuccess(int $customerId, int $amountCents): void
    {
        Redis::incrby($this->kpiKey('revenue_cents'), $amountCents);
        Redis::incr($this->kpiKey('order_count'));

        $revenue = (int) Redis::get($this->kpiKey('revenue_cents'));
        $count = (int) Redis::get($this->kpiKey('order_count'));

        if ($count > 0) {
            Redis::set($this->kpiKey('avg_order_value_cents'), (int) floor($revenue / $count));
        }

        Redis::zincrby($this->leaderboardKey(), $amountCents, (string) $customerId);
    }

    public function recordFailure(int $customerId, int $amountCents): void
    {
        Redis::decrby($this->kpiKey('revenue_cents'), $amountCents);
        Redis::zincrby($this->leaderboardKey(), -$amountCents, (string) $customerId);
    }

    public function recordRefund(int $customerId, int $amountCents): void
    {
        // daily revenue goes down
        Redis::decrby($this->kpiKey('revenue_cents'), $amountCents);

        $revenue = (int) Redis::get($this->kpiKey('revenue_cents'));
        $count = (int) Redis::get($this->kpiKey('order_count'));

        if ($count > 0) {
            Redis::set($this->kpiKey('avg_order_value_cents'), (int) floor($revenue / $count));
        }

        // leaderboard score goes down
        Redis::zincrby($this->leaderboardKey(), -$amountCents, (string) $customerId);
    }
}
