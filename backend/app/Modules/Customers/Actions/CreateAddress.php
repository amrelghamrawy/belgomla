<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;

/**
 * BR-CU03: adds a delivery address. Without a phone the customer's own phone is used. The first address,
 * or one sent with `is_default`, becomes the default. Joins the caller's transaction.
 */
final readonly class CreateAddress
{
    public function __construct(
        private SetDefaultAddress $setDefault,
        private EnsureDefaultAddress $ensureDefault,
    ) {}

    public function handle(Customer $customer, AddressData $data): CustomerAddress
    {
        return DB::transaction(function () use ($customer, $data): CustomerAddress {
            $address = CustomerAddress::query()->create([
                'customer_id' => $customer->id,
                'label' => $data->label,
                'governorate' => $data->governorate,
                'city' => $data->city,
                'address_line' => $data->addressLine,
                'landmark' => $data->landmark,
                'phone' => $data->phone ?? $customer->phone,
            ]);

            if ($data->isDefault) {
                $this->setDefault->handle($address);
            } else {
                $this->ensureDefault->handle($customer->id);
            }

            return $address->refresh();
        });
    }
}
