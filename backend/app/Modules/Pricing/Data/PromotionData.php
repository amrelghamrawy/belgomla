<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Data;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Modules\Pricing\Http\Requests\StorePromotionRequest;
use App\Modules\Pricing\Http\Requests\UpdatePromotionRequest;
use Carbon\CarbonImmutable;

/**
 * BR-PR15 (V2 Task 12, D90): a promotion as the merchant creates or edits it. The target is a product `filter` or an
 * explicit list of `skuIds`, never both. On create every field but `status` (default scheduled) is set; on a PATCH a null
 * field is left as it is (both target fields null = the same target, re-selected).
 */
final readonly class PromotionData
{
    /**
     * @param  list<int>|null  $skuIds
     */
    public function __construct(
        public ?string $name,
        public ?string $discountPercent,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public ?ProductFilterData $filter = null,
        public ?array $skuIds = null,
        public ?PromotionStatus $status = null,
    ) {}

    public static function fromRequest(StorePromotionRequest|UpdatePromotionRequest $request): self
    {
        return new self(
            name: $request->name(),
            discountPercent: $request->discountPercent(),
            startsAt: $request->startsAt(),
            endsAt: $request->endsAt(),
            filter: $request->filter(),
            skuIds: $request->skuIds(),
            status: $request->status(),
        );
    }
}
