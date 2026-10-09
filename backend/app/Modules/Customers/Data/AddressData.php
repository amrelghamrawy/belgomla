<?php

declare(strict_types=1);

namespace App\Modules\Customers\Data;

use App\Modules\Customers\Http\Requests\SaveAddressRequest;

/**
 * BR-CU03: one delivery address. `phone` is E.164 or null (then the customer's phone is used). `id` is only
 * set when the dashboard edits an existing address of a customer (D54).
 */
final readonly class AddressData
{
    public function __construct(
        public string $label,
        public string $governorate,
        public string $city,
        public string $addressLine,
        public ?string $landmark = null,
        public ?string $phone = null,
        public bool $isDefault = false,
        public ?int $id = null,
    ) {}

    public static function fromRequest(SaveAddressRequest $request): self
    {
        return $request->address();
    }
}
