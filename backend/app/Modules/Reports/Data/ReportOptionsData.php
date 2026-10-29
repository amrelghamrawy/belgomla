<?php

declare(strict_types=1);

namespace App\Modules\Reports\Data;

use App\Modules\Reports\Enums\CustomerView;
use App\Modules\Reports\Enums\ProductSort;
use App\Modules\Reports\Http\Requests\ReportFiltersRequest;

/**
 * The options of single advanced reports (V2 Task 16): the product report's order, the customer report's view and
 * dormant threshold, the inventory report's dead-stock threshold and «dead only». The export reads them for any report.
 */
final readonly class ReportOptionsData
{
    public function __construct(
        public ProductSort $sort,
        public CustomerView $view,
        public int $dormantDays,
        public int $deadDays,
        public bool $deadOnly,
    ) {}

    public static function fromRequest(ReportFiltersRequest $request): self
    {
        return new self($request->productSort(), $request->customerView(), $request->dormantDays(), $request->deadDays(), $request->deadOnly());
    }
}
