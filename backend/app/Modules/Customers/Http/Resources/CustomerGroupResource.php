<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Models\CustomerGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer group (BR-PR12, V2 Task 11): its % off the base price as a 2-decimal string, status, place in the list and
 * how many customers it holds (`customers_count`, whatever their status).
 *
 * @property-read CustomerGroup $resource
 */
final class CustomerGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $group = $this->resource;

        return [
            'id' => $group->id,
            'name' => $group->name,
            'discount_percent' => $group->discount_percent,
            'status' => $group->status->value,
            'sort_order' => $group->sort_order,
            'customers_count' => $group->customers_count ?? 0,
        ];
    }
}
