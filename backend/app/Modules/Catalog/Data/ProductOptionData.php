<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;

/**
 * A variant option of a product (BR-P14, D92: at most two per product, up to twenty values each): `id` null = a new option
 * (or the product's option with the same name), its values in the merchant's order.
 */
final readonly class ProductOptionData
{
    /**
     * @param  list<ProductOptionValueData>  $values
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public array $values,
    ) {}

    /**
     * @return list<self>
     */
    public static function fromRequest(StoreProductRequest|UpdateProductRequest $request): array
    {
        return array_map(fn (array $option): self => new self(
            id: $option['id'],
            name: $option['name'],
            values: array_map(fn (array $value): ProductOptionValueData => new ProductOptionValueData($value['id'], $value['value'], $value['imageId']), $option['values']),
        ), $request->productOptions());
    }
}
