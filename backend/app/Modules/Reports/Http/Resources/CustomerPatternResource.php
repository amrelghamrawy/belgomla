<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One customer of the customer patterns report (V2 Task 16, 08 §11): the range's orders, net revenue and average
 * order, the order rhythm over all time, the dormant flag, and what they owe and is overdue (D59, BR-C06).
 *
 * @phpstan-import-type CustomerRow from \App\Modules\Reports\Queries\CustomerPatternsQuery
 *
 * @property-read CustomerRow $resource
 */
final class CustomerPatternResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'customer' => $row['customer'],
            'customer_group' => $row['customer_group'],
            'orders' => $row['orders'],
            'revenue' => $row['revenue'],
            'average_order' => $row['average_order'],
            'first_order_at' => $row['first_order_at'],
            'last_order_at' => $row['last_order_at'],
            'lifetime_orders' => $row['lifetime_orders'],
            'average_interval_days' => $row['average_interval_days'],
            'days_since_last_order' => $row['days_since_last_order'],
            'dormant' => $row['dormant'],
            'outstanding' => $row['outstanding'],
            'overdue' => $row['overdue'],
        ];
    }
}
