<?php

declare(strict_types=1);

namespace App\Modules\Collections\Data;

use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Http\Requests\ListCollectionsRequest;
use Carbon\CarbonImmutable;

/** The collections list filters (08-api-contract.md §10); `from`/`to` are already UTC bounds of business days; `method` keeps one way of paying (D103 e). */
final readonly class CollectionFiltersData
{
    public function __construct(
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?int $customerId = null,
        public ?int $orderNumber = null,
        public ?CollectionMethod $method = null,
    ) {}

    public static function fromRequest(ListCollectionsRequest $request): self
    {
        return $request->filters();
    }
}
