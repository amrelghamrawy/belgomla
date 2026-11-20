<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Reports\Enums\CustomerView;
use App\Modules\Reports\Enums\ProductSort;
use App\Modules\Reports\Enums\SalesGrouping;
use App\Support\BusinessCalendar;
use App\Support\Http\PaginatedRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * GET /api/dashboard/reports/{sales-analytics|products|customers|inventory|collections}[/export] (V2 Task 16, 08 §11):
 * `?from&to` business days (D60, both inclusive; without them the last 30 days up to today), `group=day|week|month`,
 * `category_id`, `customer_group_id`, and the options of single reports (`sort`, `view`, `dormant_days`, `dead_days`,
 * `dead_only`, `page`, `per_page`), plus `format=xlsx|csv` for an export. A report ignores what does not apply to it.
 */
final class ReportFiltersRequest extends FormRequest
{
    /** No report reaches back before this day, so a range cannot ask for tens of thousands of empty buckets. */
    public const string EARLIEST = '2000-01-01';

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:'.self::EARLIEST],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from', 'after_or_equal:'.self::EARLIEST],
            'group' => ['sometimes', 'string', new Enum(SalesGrouping::class)],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'customer_group_id' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string', new Enum(ProductSort::class)],
            'view' => ['sometimes', 'string', new Enum(CustomerView::class)],
            'dormant_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'dead_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'dead_only' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.PaginatedRequest::MAX_PER_PAGE],
            'format' => ['sometimes', 'string', new Enum(SpreadsheetFormat::class)],
        ];
    }

    public function to(): string
    {
        return $this->filled('to') ? $this->string('to')->value() : BusinessCalendar::today($this->timezone());
    }

    public function from(): string
    {
        if ($this->filled('from')) {
            return $this->string('from')->value();
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $this->to())?->subDays(SalesReportRequest::DEFAULT_DAYS - 1)->toDateString() ?? $this->to();
    }

    public function group(): SalesGrouping
    {
        return $this->enum('group', SalesGrouping::class) ?? SalesGrouping::Day;
    }

    public function categoryId(): ?int
    {
        return $this->filled('category_id') ? $this->integer('category_id') : null;
    }

    public function customerGroupId(): ?int
    {
        return $this->filled('customer_group_id') ? $this->integer('customer_group_id') : null;
    }

    public function productSort(): ProductSort
    {
        return $this->enum('sort', ProductSort::class) ?? ProductSort::Revenue;
    }

    public function customerView(): CustomerView
    {
        return $this->enum('view', CustomerView::class) ?? CustomerView::Top;
    }

    public function dormantDays(): int
    {
        return $this->integer('dormant_days', config()->integer('reports.dormant_days'));
    }

    public function deadDays(): int
    {
        return $this->integer('dead_days', config()->integer('reports.dead_stock_days'));
    }

    public function deadOnly(): bool
    {
        return $this->boolean('dead_only');
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', config()->integer('reports.per_page'));
    }

    public function fileFormat(): SpreadsheetFormat
    {
        return $this->enum('format', SpreadsheetFormat::class) ?? SpreadsheetFormat::Xlsx;
    }

    public function timezone(): string
    {
        return config()->string('app.business_timezone');
    }
}
