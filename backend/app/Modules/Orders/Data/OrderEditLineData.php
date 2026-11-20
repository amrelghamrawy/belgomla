<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

/**
 * One line of an order as the merchant wants it (BR-O12): an existing line by its id, or a new one (`orderItemId`
 * null) by its SKU unit (D91). Quantity in that unit.
 */
final readonly class OrderEditLineData
{
    public function __construct(
        public ?int $orderItemId,
        public int $skuUnitId,
        public int $quantity,
    ) {}
}
