<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Enums\RelationType;
use App\Modules\Catalog\Models\ProductRelation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductRelation> */
final class ProductRelationFactory extends Factory
{
    protected $model = ProductRelation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => ProductFactory::new(),
            'related_product_id' => ProductFactory::new(),
            'type' => RelationType::Companion,
        ];
    }

    public function revisionOf(): static
    {
        return $this->state(['type' => RelationType::RevisionOf]);
    }
}
