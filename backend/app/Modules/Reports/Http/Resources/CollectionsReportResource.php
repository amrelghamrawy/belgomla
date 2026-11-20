<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The collections report (V2 Task 16, 08 §11): the range and grouping, the buckets (cash, credit drawn, refunds as a
 * negative amount, net collected, net sales delivered), the totals with the collection rate, the range's payments per
 * method (D103 e), the aging of what is owed now (BR-C06) and when the figures were computed (D94).
 *
 * @phpstan-import-type CollectionsReport from \App\Modules\Reports\Queries\CollectionsReportQuery
 *
 * @property-read array{generated_at: string, data: CollectionsReport} $resource
 */
final class CollectionsReportResource extends JsonResource
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
            'by_method' => $report['by_method'],
            'aging' => $report['aging'],
        ];
    }
}
