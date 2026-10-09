<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A delivery address (BR-CU03, 08-api-contract.md §3).
 *
 * @property-read CustomerAddress $resource
 */
final class AddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $address = $this->resource;

        return [
            'id' => $address->id,
            'label' => $address->label,
            'governorate' => $address->governorate,
            'city' => $address->city,
            'address_line' => $address->address_line,
            'landmark' => $address->landmark,
            'phone' => $address->phone,
            'is_default' => $address->is_default,
        ];
    }
}
