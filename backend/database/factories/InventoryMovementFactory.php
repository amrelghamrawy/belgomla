<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryMovement> */
final class InventoryMovementFactory extends Factory
{
    protected $model = InventoryMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku_id' => SkuFactory::new(),
            'type' => MovementType::Opening,
            'quantity' => 100,
            'on_hand_after' => 100,
            'order_id' => null,
            'reason' => null,
            'actor_type' => ActorType::System,
            'actor_id' => null,
        ];
    }

    public function adjustment(int $quantity, int $onHandAfter, string $reason = 'جرد'): static
    {
        return $this->state(['type' => MovementType::Adjustment, 'quantity' => $quantity, 'on_hand_after' => $onHandAfter, 'reason' => $reason]);
    }

    public function sale(int $quantity, int $onHandAfter, int $orderId): static
    {
        return $this->state(['type' => MovementType::Sale, 'quantity' => -$quantity, 'on_hand_after' => $onHandAfter, 'order_id' => $orderId]);
    }
}
