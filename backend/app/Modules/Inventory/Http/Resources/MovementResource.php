<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ledger line (BR-INV06): signed quantity and resulting On Hand in base units, the order for a `sale`,
 * the reason for an `adjustment`, and who made it.
 *
 * @property-read InventoryMovement $resource
 */
final class MovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $movement = $this->resource;

        return [
            'id' => $movement->id,
            'type' => $movement->type->value,
            'type_label' => $movement->type->label(),
            'quantity' => $movement->quantity,
            'on_hand_after' => $movement->on_hand_after,
            'reason' => $movement->reason,
            'order_id' => $movement->order_id,
            'actor' => ['type' => $movement->actor_type->value, 'id' => $movement->actor_id],
            'created_at' => $movement->created_at->toIso8601String(),
        ];
    }
}
