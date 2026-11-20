<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\ProductOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductOption> */
final class ProductOptionFactory extends Factory
{
    protected $model = ProductOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => ProductFactory::new(),
            'name' => 'خيار '.fake()->unique()->numberBetween(1, 9_999_999),
            'sort_order' => 0,
        ];
    }
}
