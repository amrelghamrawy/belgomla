<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Requests;

use App\Modules\Collections\Data\CollectionFiltersData;
use App\Modules\Collections\Enums\CollectionMethod;
use App\Support\BusinessCalendar;
use App\Support\Http\PaginatedRequest;

/**
 * GET /api/dashboard/collections: `?from=&to=&customer_id=&order=&method=` (08-api-contract.md §10, D103 e). `from`/`to` are days of
 * `collected_at` in the business time zone (D60), both inclusive; `order` is the order number.
 */
final class ListCollectionsRequest extends PaginatedRequest
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
            'order' => ['sometimes', 'integer', 'min:1'],
            'method' => ['sometimes', 'string', 'in:'.implode(',', array_column(CollectionMethod::cases(), 'value'))],
        ];
    }

    public function filters(): CollectionFiltersData
    {
        $timezone = config()->string('app.business_timezone');

        return new CollectionFiltersData(
            from: $this->filled('from') ? BusinessCalendar::dayStart($this->string('from')->value(), $timezone) : null,
            to: $this->filled('to') ? BusinessCalendar::dayEnd($this->string('to')->value(), $timezone) : null,
            customerId: $this->has('customer_id') ? $this->integer('customer_id') : null,
            orderNumber: $this->has('order') ? $this->integer('order') : null,
            method: $this->enum('method', CollectionMethod::class),
        );
    }
}
