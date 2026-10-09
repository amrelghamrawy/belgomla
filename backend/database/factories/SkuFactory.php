<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Sku;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Sku> */
final class SkuFactory extends Factory
{
    protected $model = Sku::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => ProductFactory::new(),
            'code' => 'SKU-'.fake()->unique()->numberBetween(1, 9_999_999),
            'name' => null,
            'selling_unit_id' => UnitFactory::new(),
            'base_unit_id' => UnitFactory::new(),
            'conversion_factor' => 1,
            'cover_price' => null,
            'base_price' => '100.00',
            'base_price_percent' => null,
            'moq' => 1,
        ];
    }

    public function withCoverPrice(string $coverPrice = '120.00', ?string $percent = null): static
    {
        return $this->state(['cover_price' => $coverPrice, 'base_price_percent' => $percent]);
    }

    /** Stock in base units (BR-INV02); without it the SKU has no inventory row, i.e. no stock. */
    public function withStock(int $onHand, int $reserved = 0): static
    {
        return $this->has(SkuInventoryFactory::new()->stock($onHand, $reserved), 'inventory');
    }
}
