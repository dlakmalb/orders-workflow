<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'amount_cents' => fake()->numberBetween(100, 10000),
            'reason' => fake()->optional()->sentence(),
            'status' => Refund::STATUS_REQUESTED,
            'idempotency_key' => fake()->optional()->uuid(),
            'processed_at' => null,
        ];
    }

    /**
     * Indicate that the refund is requested.
     */
    public function requested(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Refund::STATUS_REQUESTED,
            'processed_at' => null,
        ]);
    }

    /**
     * Indicate that the refund is processed.
     */
    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Refund::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);
    }

    /**
     * Indicate that the refund failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Refund::STATUS_FAILED,
            'processed_at' => now(),
        ]);
    }
}
