<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Models\SkuInventory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SkuInventory> */
final class SkuInventoryFactory extends Factory
{
    protected $model = SkuInventory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku_id' => SkuFactory::new(),
            'on_hand' => 100,
            'reserved' => 0,
        ];
    }

    public function stock(int $onHand, int $reserved = 0, ?string $avgCost = null): static
    {
        return $this->state(['on_hand' => $onHand, 'reserved' => $reserved, 'avg_cost' => $avgCost]);
    }
}
