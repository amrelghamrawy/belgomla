<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use App\Support\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/dashboard/reports/bundles: `?from=&to=` (BR-P15, D93), read like the sales report's range: business days
 * (D60), both inclusive; without them the last 30 days up to today.
 */
final class BundleReportRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
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

    public function timezone(): string
    {
        return config()->string('app.business_timezone');
    }
}
