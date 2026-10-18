<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Data\ReturnFiltersData;
use App\Support\BusinessCalendar;
use App\Support\Http\PaginatedRequest;

/**
 * GET /api/dashboard/returns: `?from=&to=&customer_id=&q=` (08-api-contract.md §9). `from`/`to` are days of
 * `returned_at` in the business time zone (D60), both inclusive; `q` is an order number (`#1024` too) or a
 * customer name.
 */
final class ListReturnsRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'customer_id' => ['sometimes', 'integer', 'min:1'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function filters(): ReturnFiltersData
    {
        $timezone = config()->string('app.business_timezone');
        $q = mb_trim($this->string('q')->value());

        return new ReturnFiltersData(
            from: $this->filled('from') ? BusinessCalendar::dayStart($this->string('from')->value(), $timezone) : null,
            to: $this->filled('to') ? BusinessCalendar::dayEnd($this->string('to')->value(), $timezone) : null,
            customerId: $this->has('customer_id') ? $this->integer('customer_id') : null,
            search: $q === '' ? null : $q,
        );
    }
}
