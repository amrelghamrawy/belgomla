<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Pricing\Models\SkuPriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SkuPriceTier> */
final class SkuPriceTierFactory extends Factory
{
    protected $model = SkuPriceTier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku_id' => SkuFactory::new(),
            'min_quantity' => 10,
            'unit_price' => '90.00',
            'discount_percent' => null,
        ];
    }

    /** A tier entered as a % off the cover price (BR-PR10). */
    public function percent(string $discountPercent): static
    {
        return $this->state(['discount_percent' => $discountPercent]);
    }
}
