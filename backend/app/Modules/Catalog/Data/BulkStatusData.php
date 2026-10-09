<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Http\Requests\BulkProductStatusRequest;

/** BR-P12: set one status on every product matching a filter. */
final readonly class BulkStatusData
{
    public function __construct(
        public ProductFilterData $filter,
        public ProductStatus $status,
    ) {}

    public static function fromRequest(BulkProductStatusRequest $request): self
    {
        return new self(
            filter: ProductFilterData::fromRequest($request),
            status: $request->status(),
        );
    }
}
