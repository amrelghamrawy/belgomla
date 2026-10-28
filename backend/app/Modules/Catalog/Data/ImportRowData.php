<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Enums\ProductStatus;

/**
 * One product of a catalog file turned into what the catalog actions take (V2 Task 06; its SKUs since V2 Task 14): the
 * product with its options, SKUs and prices, the status the file asks for (null = keep, or active for a new product),
 * per SKU of `product->skus` the On Hand to set in base units (`onHand`; a SKU left out leaves its stock alone, a new SKU
 * starts with it as opening stock), and per SKU the file rows of its units (`rows`; a SKU the file does not list: the
 * product's first row), so an error of the actions can name its row. A read model, not request input.
 */
final readonly class ImportRowData
{
    /**
     * @param  array<int, int>  $onHand  index of `product->skus` => On Hand
     * @param  list<list<int>>  $rows  index of `product->skus` => row numbers, unit by unit
     */
    public function __construct(
        public ProductData $product,
        public ?ProductStatus $status = null,
        public array $onHand = [],
        public array $rows = [],
    ) {}
}
