<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Http\Requests\ListDashboardOrdersRequest;
use Carbon\CarbonImmutable;

/** The dashboard order list filters (08-api-contract.md §9); `stale` means pending past `stale_pending_hours` (BR-INV07). */
final readonly class OrderFiltersData
{
    /**
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     */
    public function __construct(
        public ?OrderStatus $status = null,
        public ?CollectionStatus $collectionStatus = null,
        public ?int $customerId = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?string $search = null,
        public bool $stale = false,
        public array $sort = ['placed_at', 'desc'],
    ) {}

    public static function fromRequest(ListDashboardOrdersRequest $request): self
    {
        return $request->filters();
    }
}
