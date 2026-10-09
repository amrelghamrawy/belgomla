<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Http\Requests\BulkProductStatusRequest;
use App\Modules\Catalog\Http\Requests\ListDashboardProductsRequest;
use App\Modules\Catalog\Http\Requests\StoreProductListRequest;

/**
 * The product selection shared by the store list, facets, price list, dashboard list and bulk
 * actions (08-api-contract.md §1, §6): category (id on the dashboard, slug on the store, descendants
 * included), custom field values `f[key]` combined with AND, search `q`, and status.
 */
final readonly class ProductFilterData
{
    /**
     * @param  array<string, string>  $fields  field key => option id | "true" | "false" | raw value
     */
    public function __construct(
        public ?int $categoryId = null,
        public ?string $categorySlug = null,
        public array $fields = [],
        public ?string $q = null,
        public ?ProductStatus $status = null,
    ) {}

    public static function fromRequest(ListDashboardProductsRequest|BulkProductStatusRequest|StoreProductListRequest $request): self
    {
        $input = $request->productFilterInput();

        return new self(
            categoryId: $input['categoryId'],
            categorySlug: $input['categorySlug'],
            fields: $input['fields'],
            q: $input['q'],
            status: $input['status'],
        );
    }

    public function isEmpty(): bool
    {
        return $this->categoryId === null && $this->categorySlug === null && $this->fields === [] && $this->q === null && ! $this->status instanceof ProductStatus;
    }

    /**
     * The same selection without one facet: facet counts for `key` ignore its own selection (BR-P08).
     */
    public function without(string $key): self
    {
        return new self($this->categoryId, $this->categorySlug, array_diff_key($this->fields, [$key => true]), $this->q, $this->status);
    }
}
