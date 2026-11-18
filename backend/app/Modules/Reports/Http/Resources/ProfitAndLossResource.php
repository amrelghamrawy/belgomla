<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/dashboard/reports/profit-and-loss (D100): the range, its age, and the figures of the range and of the one
 * just before.
 *
 * @phpstan-import-type ProfitAndLoss from \App\Modules\Reports\Queries\ProfitAndLossQuery
 *
 * @property-read array{generated_at: string, data: ProfitAndLoss} $resource
 */
final class ProfitAndLossResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->resource['data'];

        return [
            'from' => $report['current']['from'],
            'to' => $report['current']['to'],
            'generated_at' => $this->resource['generated_at'],
            'current' => $report['current'],
            'previous' => $report['previous'],
        ];
    }
}
