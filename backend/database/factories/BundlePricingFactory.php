<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Pricing\Enums\BundlePriceMode;
use App\Modules\Pricing\Models\BundlePricing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BundlePricing> */
final class BundlePricingFactory extends Factory
{
    protected $model = BundlePricing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bundle_sku_id' => SkuFactory::new()->state(['product_id' => ProductFactory::new()->bundle()]),
            'mode' => BundlePriceMode::Fixed,
            'value' => '100.00',
        ];
    }

    /** The components' sum less `$percent` %. */
    public function percentOffSum(string $percent = '10.00'): static
    {
        return $this->state(['mode' => BundlePriceMode::PercentOffSum, 'value' => $percent]);
    }
}
