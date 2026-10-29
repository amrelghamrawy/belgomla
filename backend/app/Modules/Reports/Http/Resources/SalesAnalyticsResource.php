<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The sales analytics report (V2 Task 16, 08 §11): the range and grouping, the buckets (orders, gross, discounts,
 * returns, net), the totals with the average order, the previous period of the same length, the split by section
 * (D68) and by customer group (BR-PR12), and when the figures were computed (D94).
 *
 * @phpstan-import-type SalesAnalytics from \App\Modules\Reports\Queries\SalesAnalyticsQuery
 *
 * @property-read array{generated_at: string, data: SalesAnalytics} $resource
 */
final class SalesAnalyticsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->resource['data'];

        return [
            'from' => $report['from'],
            'to' => $report['to'],
            'group' => $report['group'],
            'generated_at' => $this->resource['generated_at'],
            'buckets' => $report['buckets'],
            'totals' => $report['totals'],
            'previous' => $report['previous'],
            'by_section' => $report['by_section'],
            'by_customer_group' => $report['by_customer_group'],
        ];
    }
}
