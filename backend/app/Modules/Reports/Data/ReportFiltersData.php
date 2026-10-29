<?php

declare(strict_types=1);

namespace App\Modules\Reports\Data;

use App\Modules\Reports\Enums\SalesGrouping;
use App\Modules\Reports\Http\Requests\ReportFiltersRequest;

/**
 * The filters every advanced report takes (V2 Task 16): the range (`Y-m-d` business days, both inclusive, D60), the
 * bucket size (Saturday weeks, D58), a category (with its sub-categories) and a customer group, plus the business time
 * zone. A report ignores a filter that does not apply to it (08 §11).
 */
final readonly class ReportFiltersData
{
    public function __construct(
        public string $from,
        public string $to,
        public SalesGrouping $group,
        public ?int $categoryId,
        public ?int $customerGroupId,
        public string $timezone,
    ) {}

    public static function fromRequest(ReportFiltersRequest $request): self
    {
        return new self(
            $request->from(),
            $request->to(),
            $request->group(),
            $request->categoryId(),
            $request->customerGroupId(),
            $request->timezone(),
        );
    }
}
