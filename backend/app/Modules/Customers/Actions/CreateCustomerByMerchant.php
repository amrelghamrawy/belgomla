<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Data\CustomerData;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\PhoneTaken;
use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * BR-CU08: the merchant creates a customer with a name, a phone and optional addresses. The customer is
 * `invited` with no password until they use an invitation link; the merchant can refer to them meanwhile.
 * Audited as `customer.created` (BR-L). Owns its transaction.
 */
final readonly class CreateCustomerByMerchant
{
    public function __construct(
        private CreateAddress $createAddress,
        private AuditLogger $audit,
    ) {}

    public function handle(CustomerData $data, ?int $actorId = null): Customer
    {
        if (Customer::query()->where('phone', $data->phone)->exists()) {
            throw PhoneTaken::make();
        }

        return DB::transaction(function () use ($data, $actorId): Customer {
            $customer = Customer::query()->create([
                'name' => $data->name,
                'phone' => $data->phone,
                'status' => CustomerStatus::Invited,
                'created_by' => $actorId,
            ]);

            foreach ($data->addresses ?? [] as $address) {
                $this->createAddress->handle($customer, $address);
            }

            $this->audit->log('customer.created', $customer, null, [
                'name' => $customer->name,
                'phone' => $customer->phone,
                'status' => $customer->status->value,
            ]);

            return $customer;
        });
    }
}
