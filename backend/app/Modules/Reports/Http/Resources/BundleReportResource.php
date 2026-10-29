<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use App\Modules\Reports\Data\BundleReportData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The bundle report (BR-P15, D93): the range it covers, the bundles delivered in it (quantity and total, most sold
 * first) and the component SKUs they consumed in base units (most first).
 *
 * @phpstan-import-type BundleReport from \App\Modules\Reports\Queries\BundleSalesQuery
 *
 * @property-read BundleReport $resource
 */
final class BundleReportResource extends JsonResource
{
    /**
     * @param  BundleReport  $resource
     */
    public function __construct(array $resource, private readonly BundleReportData $report)
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
            'bundles' => $this->resource['bundles'],
            'components' => $this->resource['components'],
        ];
    }
}
