<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The dashboard overview (08-api-contract.md §11, D5): every order status with its count (zeros included), today's
 * orders, stale pending orders, the outstanding total and the low-stock count.
 *
 * @property-read array{orders_by_status: array<string, int>, today: array{orders: int, total: string}, stale_pending: int, outstanding_total: string, low_stock_count: int} $resource
 */
final class OverviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'orders_by_status' => $this->resource['orders_by_status'],
            'today' => $this->resource['today'],
            'stale_pending' => $this->resource['stale_pending'],
            'outstanding_total' => $this->resource['outstanding_total'],
            'low_stock_count' => $this->resource['low_stock_count'],
        ];
    }
}
