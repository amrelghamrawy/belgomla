<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;

/**
 * Create or replace a product with its SKUs (BR-P02): one SKU without options, one per variant with them (BR-P14, V2
 * Task 14; `options` at most two, D92). `customFields` is the raw map, validated against the definitions by
 * CustomFieldValidator inside the action. `status` is create-only; each SKU carries its own opening stock (base units,
 * BR-U03), applied when it is created. `preorder` null = not sent: off on a create, kept on an update (D88).
 */
final readonly class ProductData
{
    /**
     * @param  non-empty-list<SkuData>  $skus
     * @param  list<ProductOptionData>  $options
     * @param  array<array-key, mixed>  $customFields
     */
    public function __construct(
        public string $name,
        public int $categoryId,
        public array $skus,
        public array $options = [],
        public ?string $description = null,
        public array $customFields = [],
        public ?ProductStatus $status = null,
        public ?PreorderData $preorder = null,
    ) {}

    public static function fromRequest(StoreProductRequest|UpdateProductRequest $request): self
    {
        $paths = $request->skuPaths();
        $first = array_shift($paths) ?? 'sku';

        return new self(
            name: $request->name(),
            categoryId: $request->categoryId(),
            skus: [SkuData::fromRequest($request, $first), ...array_map(fn (string $path): SkuData => SkuData::fromRequest($request, $path), $paths)],
            options: ProductOptionData::fromRequest($request),
            description: $request->description(),
            customFields: $request->customFields(),
            status: $request instanceof StoreProductRequest ? $request->status() : null,
            preorder: PreorderData::fromRequest($request),
        );
    }
}
