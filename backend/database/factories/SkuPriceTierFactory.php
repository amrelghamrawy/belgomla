<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Pricing\Models\SkuPriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A quantity tier of a SKU unit (BR-PR03). Given `sku_id` instead of `sku_unit_id`, it belongs to that SKU's default unit.
 *
 * @extends Factory<SkuPriceTier>
 */
final class SkuPriceTierFactory extends Factory
{
    protected $model = SkuPriceTier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku_unit_id' => fn (array $attributes): int => array_key_exists('sku_id', $attributes)
                ? SkuFactory::defaultUnitIdOf($attributes['sku_id'])
                : SkuFactory::defaultUnitIdOf(SkuFactory::new()->createOne()->id),
            'min_quantity' => 10,
            'unit_price' => '90.00',
            'discount_percent' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (SkuPriceTier $tier): void {
            unset($tier->sku_id);
        });
    }

    /** A tier entered as a % off the cover price (BR-PR10). */
    public function percent(string $discountPercent): static
    {
        return $this->state(['discount_percent' => $discountPercent]);
    }
}
