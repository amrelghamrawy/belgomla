<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Data\CustomerData;
use App\Modules\Customers\Enums\AddressStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;

/**
 * The merchant edits a customer's name and, when sent, their address list (08-api-contract.md §8, D54): rows
 * with an id are edited, rows without one are added, active addresses missing from the list are archived.
 * The phone never changes here. An address id of another customer is `404`. Audited as `customer.updated`.
 * Owns its transaction.
 */
final readonly class UpdateCustomerByMerchant
{
    public function __construct(
        private CreateAddress $createAddress,
        private UpdateAddress $updateAddress,
        private ArchiveAddress $archiveAddress,
        private AuditLogger $audit,
    ) {}

    public function handle(Customer $customer, CustomerData $data): Customer
    {
        return DB::transaction(function () use ($customer, $data): Customer {
            $old = ['name' => $customer->name, 'address_ids' => $this->activeAddressIds($customer)];

            $customer->forceFill(['name' => $data->name])->save();

            if ($data->addresses !== null) {
                $this->syncAddresses($customer, $data->addresses);
            }

            $new = ['name' => $customer->name, 'address_ids' => $this->activeAddressIds($customer)];

            if ($old !== $new || $data->addresses !== null) {
                $this->audit->log('customer.updated', $customer, $old, $new);
            }

            return $customer;
        });
    }

    /**
     * @return list<int>
     */
    private function activeAddressIds(Customer $customer): array
    {
        return array_values(CustomerAddress::query()
            ->where('customer_id', $customer->id)
            ->where('status', AddressStatus::Active)
            ->orderBy('id')
            ->get(['id'])
            ->map(fn (CustomerAddress $address): int => $address->id)
            ->all());
    }

    /**
     * @param  list<AddressData>  $addresses
     */
    private function syncAddresses(Customer $customer, array $addresses): void
    {
        $active = CustomerAddress::query()
            ->where('customer_id', $customer->id)
            ->where('status', AddressStatus::Active)
            ->get()
            ->keyBy('id');

        $kept = [];

        foreach ($addresses as $address) {
            if ($address->id === null) {
                $this->createAddress->handle($customer, $address);

                continue;
            }

            $existing = $active->get($address->id) ?? CustomerAddress::query()->where('customer_id', $customer->id)->findOrFail($address->id);
            $this->updateAddress->handle($existing, $address);
            $kept[] = $existing->id;
        }

        foreach ($active->except($kept) as $removed) {
            $this->archiveAddress->handle($removed);
        }
    }
}
