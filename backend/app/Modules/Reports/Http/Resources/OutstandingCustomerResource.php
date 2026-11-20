<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One customer of the outstanding report (08-api-contract.md §11, D5 b): the delivered orders not fully collected,
 * their total, what was collected and what is outstanding (D59), aged by days past due (BR-C06) with the overdue sum.
 *
 * @property-read object{customer_id: int, customer_name: string, customer_phone: string, orders_count: int|string, total: string, collected: string, outstanding: string, aging_current: string, aging_0_30: string, aging_31_60: string, aging_61_90: string, aging_over_90: string, overdue: string} $resource
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
            'aging' => [
                'current' => Money::add('0.00', $row->aging_current),
                '0_30' => Money::add('0.00', $row->aging_0_30),
                '31_60' => Money::add('0.00', $row->aging_31_60),
                '61_90' => Money::add('0.00', $row->aging_61_90),
                'over_90' => Money::add('0.00', $row->aging_over_90),
            ],
            'overdue' => Money::add('0.00', $row->overdue),
        ];
    }
}
