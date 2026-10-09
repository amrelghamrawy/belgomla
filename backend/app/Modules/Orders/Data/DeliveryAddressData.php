<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Http\Requests\UpdateDeliveryAddressRequest;

/**
 * BR-O10, D9: the merchant's new delivery snapshot. A null `name` or `phone` keeps the current one; `phone` is
 * E.164.
 */
final readonly class DeliveryAddressData
{
    public function __construct(
        public string $governorate,
        public string $city,
        public string $addressLine,
        public ?string $label = null,
        public ?string $landmark = null,
        public ?string $name = null,
        public ?string $phone = null,
    ) {}

    public static function fromRequest(UpdateDeliveryAddressRequest $request): self
    {
        return new self(
            governorate: $request->optionalText('governorate') ?? '',
            city: $request->optionalText('city') ?? '',
            addressLine: $request->optionalText('address_line') ?? '',
            label: $request->optionalText('label'),
            landmark: $request->optionalText('landmark'),
            name: $request->optionalText('name'),
            phone: $request->phone(),
        );
    }

    /**
     * The `delivery_address` snapshot, in the same keys as checkout writes (OrderSnapshots).
     *
     * @return array{label: string|null, governorate: string, city: string, address_line: string, landmark: string|null}
     */
    public function address(): array
    {
        return [
            'label' => $this->label,
            'governorate' => $this->governorate,
            'city' => $this->city,
            'address_line' => $this->addressLine,
            'landmark' => $this->landmark,
        ];
    }
}
