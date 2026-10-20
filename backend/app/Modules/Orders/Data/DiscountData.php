<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Http\Requests\ApplyOrderDiscountRequest;
use App\Modules\Pricing\Enums\DiscountType;

/**
 * The manual discounts of an order as they should be (BR-PR08, D85): per line (a line left out has none) and on the
 * whole order (no type = none; a reason is required with one). Built from PUT /api/dashboard/orders/{number}/discount,
 * or by PlaceOrderForCustomer from the order form.
 */
final readonly class DiscountData
{
    /**
     * @param  list<LineDiscountData>  $items
     */
    public function __construct(
        public array $items = [],
        public ?DiscountType $type = null,
        public ?string $value = null,
        public ?string $reason = null,
    ) {}

    public static function fromRequest(ApplyOrderDiscountRequest $request): self
    {
        return new self($request->items(), $request->orderType(), $request->orderValue(), $request->reason());
    }
}
