<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use App\Modules\Reports\Enums\SalesGrouping;
use App\Support\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * GET /api/dashboard/reports/sales: `?from=&to=&group=day|week|month` (08-api-contract.md §11). Days are business
 * days (D60), both inclusive; without them the report covers the last 30 days up to today, grouped by day.
 */
final class SalesReportRequest extends FormRequest
{
    public const int DEFAULT_DAYS = 30;

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group' => ['sometimes', 'string', new Enum(SalesGrouping::class)],
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

        return CarbonImmutable::createFromFormat('!Y-m-d', $this->to())?->subDays(self::DEFAULT_DAYS - 1)->toDateString() ?? $this->to();
    }

    public function group(): SalesGrouping
    {
        return $this->enum('group', SalesGrouping::class) ?? SalesGrouping::Day;
    }

    public function timezone(): string
    {
        return config()->string('app.business_timezone');
    }
}
