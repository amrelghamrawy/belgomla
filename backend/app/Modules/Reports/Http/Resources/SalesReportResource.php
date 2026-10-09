<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use App\Modules\Reports\Data\SalesReportData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The sales report (08-api-contract.md §11, D5 a): the range and grouping it covers, one bucket per day, week
 * (from Saturday, D58) or month with delivered orders and their total, and the totals of the range.
 *
 * @property-read array{buckets: list<array{period_start: string, orders: int, total: string}>, orders: int, total: string} $resource
 */
final class SalesReportResource extends JsonResource
{
    /**
     * @param  array{buckets: list<array{period_start: string, orders: int, total: string}>, orders: int, total: string}  $resource
     */
    public function __construct(array $resource, private readonly SalesReportData $report)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from' => $this->report->from,
            'to' => $this->report->to,
            'group' => $this->report->group->value,
            'buckets' => $this->resource['buckets'],
            'totals' => [
                'orders' => $this->resource['orders'],
                'total' => $this->resource['total'],
            ],
        ];
    }
}
