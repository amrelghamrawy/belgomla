<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use App\Modules\Reports\Data\SalesReportData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The sales report (08-api-contract.md §11, D5 a): the range and grouping it covers, one bucket per day, week
 * (from Saturday, D58) or month with delivered orders, their gross total, the returns and the net (BR-R05), and the
 * totals of the range.
 *
 * @property-read array{buckets: list<array{period_start: string, orders: int, gross: string, returns: string, net: string}>, orders: int, gross: string, returns: string, net: string} $resource
 */
final class SalesReportResource extends JsonResource
{
    /**
     * @param  array{buckets: list<array{period_start: string, orders: int, gross: string, returns: string, net: string}>, orders: int, gross: string, returns: string, net: string}  $resource
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
                'gross' => $this->resource['gross'],
                'returns' => $this->resource['returns'],
                'net' => $this->resource['net'],
            ],
        ];
    }
}
