<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Http\Requests\ListReturnsRequest;
use Carbon\CarbonImmutable;

/** The returns list filters (08-api-contract.md §9); `from`/`to` are already UTC bounds of business days. */
final readonly class ReturnFiltersData
{
    public function __construct(
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?int $customerId = null,
        public ?string $search = null,
    ) {}

    public static function fromRequest(ListReturnsRequest $request): self
    {
        return $request->filters();
    }
}
