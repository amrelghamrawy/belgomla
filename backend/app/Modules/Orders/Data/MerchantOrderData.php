<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Http\Requests\StoreMerchantOrderRequest;
use App\Modules\Pricing\Enums\DiscountType;

/**
 * POST /api/dashboard/orders input (BR-O11): for which customer and address, the lines (a SKU unit and a quantity in it, D91), the note,
 * whether to tell the customer on WhatsApp, whether the minimum order value is waived for this order (D84), and the
 * discounts given on the form, per line and on the order (D85).
 */
final readonly class MerchantOrderData
{
    /**
     * @param  list<array{sku_unit_id: int, quantity: int}>  $lines
     * @param  array<int, array{type: DiscountType, value: string}>  $lineDiscounts  SKU unit id => the line's discount
     */
    public function __construct(
        public int $customerId,
        public int $addressId,
        public array $lines,
        public ?string $note = null,
        public bool $notifyCustomer = false,
        public bool $overrideMov = false,
        public array $lineDiscounts = [],
        public ?DiscountType $discountType = null,
        public ?string $discountValue = null,
        public ?string $discountReason = null,
    ) {}

    public static function fromRequest(StoreMerchantOrderRequest $request): self
    {
        return new self(
            $request->customerId(),
            $request->addressId(),
            $request->lines(),
            $request->note(),
            $request->notifyCustomer(),
            $request->overrideMov(),
            $request->lineDiscounts(),
            $request->discountType(),
            $request->discountValue(),
            $request->discountReason(),
        );
    }
}
