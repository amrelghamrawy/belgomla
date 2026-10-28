<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\ProductOptionValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductOptionValue> */
final class ProductOptionValueFactory extends Factory
{
    protected $model = ProductOptionValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_option_id' => ProductOptionFactory::new(),
            'value' => 'قيمة '.fake()->unique()->numberBetween(1, 9_999_999),
            'sort_order' => 0,
            'image_id' => null,
        ];
    }
}
