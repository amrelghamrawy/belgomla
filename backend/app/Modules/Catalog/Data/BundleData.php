<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Http\Requests\StoreBundleRequest;
use App\Modules\Catalog\Http\Requests\UpdateBundleRequest;
use App\Modules\Pricing\Data\BundlePricingData;

/**
 * Create or replace a bundle (BR-P15, V2 Task 15, D93): the product fields, its one SKU (`code`, sold in the store unit
 * `unitId` with factor 1 and its MOQ), the components in the merchant's order and how it is priced. `status` is
 * create-only (active or inactive; a generated grade set starts inactive). `customFields` is the raw map, validated
 * against the category's definitions like a product's.
 */
final readonly class BundleData
{
    /**
     * @param  non-empty-list<BundleComponentData>  $components
     * @param  array<array-key, mixed>  $customFields
     */
    public function __construct(
        public string $name,
        public int $categoryId,
        public string $code,
        public int $unitId,
        public array $components,
        public BundlePricingData $pricing,
        public int $moq = 1,
        public ?string $description = null,
        public array $customFields = [],
        public ?ProductStatus $status = null,
    ) {}

    public static function fromRequest(StoreBundleRequest|UpdateBundleRequest $request): self
    {
        return new self(
            name: $request->name(),
            categoryId: $request->categoryId(),
            code: $request->code(),
            unitId: $request->unitId(),
            components: $request->components(),
            pricing: $request->pricing(),
            moq: $request->moq(),
            description: $request->description(),
            customFields: $request->customFields(),
            status: $request instanceof StoreBundleRequest ? $request->status() : null,
        );
    }
}
