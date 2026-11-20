<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Enums\PreorderState;
use App\Modules\Orders\Http\Requests\ListPreordersRequest;

/** GET /api/dashboard/preorders filters (BR-O14): the pre-orders holding a product, and where they stand (D88). */
final readonly class PreorderFiltersData
{
    public function __construct(
        public ?int $productId = null,
        public ?PreorderState $state = null,
    ) {}

    public static function fromRequest(ListPreordersRequest $request): self
    {
        return new self($request->productId(), $request->state());
    }
}
