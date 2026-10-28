<?php

declare(strict_types=1);

namespace App\Modules\Customers\Data;

use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Http\Requests\AddCartItemRequest;

/**
 * BR-CU06: a SKU unit (V2 Task 13, D91; or a SKU, meaning its default unit) and a quantity in that unit (BR-U04, BR-U05),
 * to order now or to pre-order (BR-O14).
 */
final readonly class CartLineData
{
    public function __construct(
        public ?int $skuId,
        public int $quantity,
        public CartLineKind $kind = CartLineKind::Order,
        public ?int $skuUnitId = null,
    ) {}

    public static function fromRequest(AddCartItemRequest $request): self
    {
        return new self($request->skuId(), $request->quantity(), $request->kind(), $request->skuUnitId());
    }
}
