<?php

declare(strict_types=1);

namespace App\Modules\Customers\Data;

use App\Modules\Customers\Http\Requests\AddCartItemRequest;

/** BR-CU06: a SKU and a quantity in its selling unit (BR-U04, BR-U05). */
final readonly class CartLineData
{
    public function __construct(
        public int $skuId,
        public int $quantity,
    ) {}

    public static function fromRequest(AddCartItemRequest $request): self
    {
        return new self($request->skuId(), $request->quantity());
    }
}
