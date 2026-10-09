<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One customer of the outstanding report (08-api-contract.md §11, D5 b): the delivered orders not fully collected,
 * their total, what was collected and what is outstanding (D59).
 *
 * @property-read object{customer_id: int, customer_name: string, customer_phone: string, orders_count: int|string, total: string, collected: string, outstanding: string} $resource
 */
final class OutstandingCustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'customer' => [
                'id' => (int) $row->customer_id,
                'name' => $row->customer_name,
                'phone' => $row->customer_phone,
            ],
            'orders' => (int) $row->orders_count,
            'total' => Money::add('0.00', $row->total),
            'collected' => Money::add('0.00', $row->collected),
            'outstanding' => Money::add('0.00', $row->outstanding),
        ];
    }
}
