<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => 'SKU-'.fake()->unique()->bothify('???-####'),
            'name' => fake()->words(3, true),
            'price_cents' => fake()->numberBetween(100, 100000),
            'stock_qty' => fake()->numberBetween(0, 1000),
        ];
    }

    /**
     * Indicate that the product is out of stock.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock_qty' => 0,
        ]);
    }

    /**
     * Indicate that the product has sufficient stock.
     */
    public function inStock(int $quantity = 100): static
    {
        return $this->state(fn (array $attributes) => [
            'stock_qty' => $quantity,
        ]);
    }
}
