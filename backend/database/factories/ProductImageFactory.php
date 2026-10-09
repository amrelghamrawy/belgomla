<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductImage> */
final class ProductImageFactory extends Factory
{
    protected $model = ProductImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => ProductFactory::new(),
            'path' => 'products/'.fake()->unique()->numberBetween(1, 9_999_999).'.jpg',
            'is_main' => false,
            'sort_order' => 0,
        ];
    }

    public function main(): static
    {
        return $this->state(['is_main' => true]);
    }
}
