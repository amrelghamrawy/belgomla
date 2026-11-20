<?php

declare(strict_types=1);

namespace App\Modules\Reports\Data;

use App\Modules\Reports\Http\Requests\BundleReportRequest;

/** The bundle report's range (`Y-m-d` business days, both inclusive) and business time zone (D60, D93). */
final readonly class BundleReportData
{
    public function __construct(
        public string $from,
        public string $to,
        public string $timezone,
    ) {}

    public static function fromRequest(BundleReportRequest $request): self
    {
        return new self($request->from(), $request->to(), $request->timezone());
    }
}
