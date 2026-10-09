<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Http\Requests\CheckoutRequest;

/** POST /api/store/checkout input: the chosen delivery address (BR-CU03) and an optional note. */
final readonly class CheckoutData
{
    public function __construct(
        public int $addressId,
        public ?string $customerNote = null,
    ) {}

    public static function fromRequest(CheckoutRequest $request): self
    {
        return new self($request->addressId(), $request->customerNote());
    }
}
