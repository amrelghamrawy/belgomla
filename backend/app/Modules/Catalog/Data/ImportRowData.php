<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Modules\Catalog\Enums\ProductStatus;

/**
 * One import row turned into what the catalog actions take (V2 Task 06): the product with its SKU and prices, the
 * status the row asks for (null = keep, or active for a new product) and the On Hand to set in base units (null =
 * the row leaves stock alone; a new product starts with it as opening stock).
 */
final readonly class ImportRowData
{
    public function __construct(
        public ProductData $product,
        public ?ProductStatus $status = null,
        public ?int $onHand = null,
    ) {}
}
