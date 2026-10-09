<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;

/**
 * Create or replace a product with its single SKU (BR-P02). `customFields` is the raw map, validated
 * against the definitions by CustomFieldValidator inside the action. `status` and `openingStock` (base
 * units, BR-U03) are create-only.
 */
final readonly class ProductData
{
    /**
     * @param  array<array-key, mixed>  $customFields
     */
    public function __construct(
        public string $name,
        public int $categoryId,
        public SkuData $sku,
        public ?string $description = null,
        public array $customFields = [],
        public ?ProductStatus $status = null,
        public int $openingStock = 0,
    ) {}

    public static function fromRequest(StoreProductRequest|UpdateProductRequest $request): self
    {
        return new self(
            name: $request->name(),
            categoryId: $request->categoryId(),
            sku: SkuData::fromRequest($request),
            description: $request->description(),
            customFields: $request->customFields(),
            status: $request instanceof StoreProductRequest ? $request->status() : null,
            openingStock: $request instanceof StoreProductRequest ? $request->openingStock() : 0,
        );
    }
}
