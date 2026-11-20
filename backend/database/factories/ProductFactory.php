<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => CategoryFactory::new(),
            'name' => 'منتج '.fake()->unique()->numberBetween(1, 9_999_999),
            'description' => null,
            'status' => ProductStatus::Active,
            'custom_fields' => [],
        ];
    }

    /**
     * The single V1 SKU (BR-P02), with stock in base units when `$onHand` is given (BR-INV02).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function withSku(array $attributes = [], ?int $onHand = null, int $reserved = 0): static
    {
        $sku = SkuFactory::new()->state($attributes);

        return $this->has($onHand === null ? $sku : $sku->withStock($onHand, $reserved), 'skus');
    }

    public function comingSoon(): static
    {
        return $this->state(['status' => ProductStatus::ComingSoon]);
    }

    /** BR-O14, D88: Coming Soon and taking pre-orders, optionally capped (selling units over every open pre-order). */
    public function preorderable(?int $limit = null, ?string $expectedAt = '2026-12-01'): static
    {
        return $this->state(['status' => ProductStatus::ComingSoon, 'preorder_enabled' => true, 'preorder_limit' => $limit, 'expected_at' => $expectedAt]);
    }

    /** BR-P15, D93: a bundle product (its SKU, components and pricing are made apart). */
    public function bundle(): static
    {
        return $this->state(['type' => ProductType::Bundle]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => ProductStatus::Inactive]);
    }
}
