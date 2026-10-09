<?php

declare(strict_types=1);

namespace App\Modules\Reports\Data;

use App\Modules\Reports\Enums\SalesGrouping;
use App\Modules\Reports\Http\Requests\SalesReportRequest;

/** The sales report's range (`Y-m-d` business days, both inclusive), bucket size and business time zone (D60). */
final readonly class SalesReportData
{
    public function __construct(
        public string $from,
        public string $to,
        public SalesGrouping $group,
        public string $timezone,
    ) {}

    public static function fromRequest(SalesReportRequest $request): self
    {
        return new self($request->from(), $request->to(), $request->group(), $request->timezone());
    }
}
