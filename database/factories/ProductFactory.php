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
    private static int $codeSeq = 0;

    public function definition(): array
    {
        self::$codeSeq++;

        return [
            'code' => 'PRD-'.str_pad((string) self::$codeSeq, 5, '0', STR_PAD_LEFT),
            'name' => fake()->words(3, true),
            'category_id' => Category::factory(),
            'unit' => fake()->randomElement(['pcs', 'sak', 'batang', 'kg', 'm', 'liter']),
            'barcode' => fake()->optional(0.5)->ean13(),
            'brand' => fake()->optional(0.5)->company(),
            'purchase_price' => fake()->optional(0.7)->numberBetween(10000, 5000000),
            'selling_price' => fake()->optional(0.7)->numberBetween(15000, 6000000),
            'min_stock' => fake()->randomFloat(3, 0, 20),
            'current_stock' => 0, // Always starts at zero; stock_in transaction required
            'photo_path' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function forCategory(Category $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => $category->id,
        ]);
    }

    public function withPhoto(string $path = 'products/test.jpg'): static
    {
        return $this->state(fn (array $attributes) => [
            'photo_path' => $path,
        ]);
    }
}
