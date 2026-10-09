<?php

declare(strict_types=1);

namespace App\Modules\Customers\Data;

use App\Modules\Customers\Http\Requests\StoreCustomerRequest;
use App\Modules\Customers\Http\Requests\UpdateCustomerRequest;

/**
 * BR-CU08: a customer the merchant creates or edits. On create the phone is required and the addresses are
 * added; on update the phone is null (it cannot change) and `addresses` null keeps them, a list syncs them (D54).
 */
final readonly class CustomerData
{
    /**
     * @param  list<AddressData>|null  $addresses
     */
    public function __construct(
        public string $name,
        public ?string $phone,
        public ?array $addresses,
    ) {}

    public static function fromRequest(StoreCustomerRequest|UpdateCustomerRequest $request): self
    {
        return new self(
            $request->name(),
            $request instanceof StoreCustomerRequest ? $request->phone() : null,
            $request->addresses(),
        );
    }
}
