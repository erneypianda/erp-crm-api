<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'sku'         => strtoupper(fake()->unique()->bothify('PRD-####')),
            'name'        => fake()->words(3, true),
            'price'       => fake()->randomFloat(2, 10, 500),
            'stock'       => fake()->numberBetween(0, 100),
            'is_active'   => true,
        ];
    }
}
