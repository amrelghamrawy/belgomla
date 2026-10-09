<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;

/**
 * BR-CU03: replaces an address's fields. `is_default: true` makes it the default; false never leaves the
 * customer without one (choose another address as default instead). Joins the caller's transaction.
 */
final readonly class UpdateAddress
{
    public function __construct(private SetDefaultAddress $setDefault) {}

    public function handle(CustomerAddress $address, AddressData $data): CustomerAddress
    {
        return DB::transaction(function () use ($address, $data): CustomerAddress {
            $address->forceFill([
                'label' => $data->label,
                'governorate' => $data->governorate,
                'city' => $data->city,
                'address_line' => $data->addressLine,
                'landmark' => $data->landmark,
                'phone' => $data->phone ?? $address->phone,
            ])->save();

            if ($data->isDefault) {
                $this->setDefault->handle($address);
            }

            return $address->refresh();
        });
    }
}
