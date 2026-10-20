<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Http\Requests\StoreMerchantOrderRequest;

/**
 * POST /api/dashboard/orders input (BR-O11): for which customer and address, the lines in selling units, the note,
 * whether to tell the customer on WhatsApp, and whether the minimum order value is waived for this order (D84).
 */
final readonly class MerchantOrderData
{
    /**
     * @param  list<array{sku_id: int, quantity: int}>  $lines
     */
    public function __construct(
        public int $customerId,
        public int $addressId,
        public array $lines,
        public ?string $note = null,
        public bool $notifyCustomer = false,
        public bool $overrideMov = false,
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
        );
    }
}
