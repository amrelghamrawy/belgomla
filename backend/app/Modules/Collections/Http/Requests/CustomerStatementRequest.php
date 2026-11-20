<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Requests;

use App\Support\BusinessCalendar;
use App\Support\Http\PaginatedRequest;
use Carbon\CarbonImmutable;

/**
 * GET /api/dashboard/customers/{id}/statement and GET /api/store/account/statement: `?from=&to=&page=&per_page=`
 * (08-api-contract.md §10, BR-C08). `from`/`to` are business days (D60), both inclusive; without them the whole history.
 */
final class CustomerStatementRequest extends PaginatedRequest
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
        ];
    }

    public function from(): ?CarbonImmutable
    {
        return $this->filled('from') ? BusinessCalendar::dayStart($this->string('from')->value(), config()->string('app.business_timezone')) : null;
    }

    public function to(): ?CarbonImmutable
    {
        return $this->filled('to') ? BusinessCalendar::dayEnd($this->string('to')->value(), config()->string('app.business_timezone')) : null;
    }
}
