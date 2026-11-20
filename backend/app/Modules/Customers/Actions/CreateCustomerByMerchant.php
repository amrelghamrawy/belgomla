<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Data\CustomerData;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\PhoneTaken;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use Illuminate\Support\Facades\DB;

/**
 * BR-CU08: the merchant creates a customer with a name, a phone and optional addresses. The customer is
 * `invited` with no password until they use an invitation link; the merchant can refer to them meanwhile. Credit
 * terms not sent come from the store's defaults (BR-C05, BR-C06). A group sent goes through SetCustomerGroup (BR-PR12).
 * Audited as `customer.created` (BR-L). Owns its transaction.
 */
final readonly class CreateCustomerByMerchant
{
    public function __construct(
        private CreateAddress $createAddress,
        private CurrentTenantSettingsQuery $settings,
        private SetCustomerGroup $setGroup,
        private AuditLogger $audit,
    ) {}

    public function handle(CustomerData $data, ?int $actorId = null): Customer
    {
        if (Customer::query()->where('phone', $data->phone)->exists()) {
            throw PhoneTaken::make();
        }

        $defaults = $this->settings->creditDefaults();

        return DB::transaction(function () use ($data, $actorId, $defaults): Customer {
            $customer = Customer::query()->create([
                'name' => $data->name,
                'phone' => $data->phone,
                'status' => CustomerStatus::Invited,
                'created_by' => $actorId,
                'credit_limit' => in_array('credit_limit', $data->creditTermsSent, true) ? $data->creditLimit : $defaults['credit_limit'],
                'payment_terms_days' => in_array('payment_terms_days', $data->creditTermsSent, true) ? $data->paymentTermsDays : $defaults['payment_terms_days'],
            ]);

            foreach ($data->addresses ?? [] as $address) {
                $this->createAddress->handle($customer, $address);
            }

            $this->audit->log('customer.created', $customer, null, [
                'name' => $customer->name,
                'phone' => $customer->phone,
                'status' => $customer->status->value,
                'credit_limit' => $customer->credit_limit,
                'payment_terms_days' => $customer->payment_terms_days,
            ]);

            if ($data->groupSent) {
                $this->setGroup->handle($customer, $data->groupId);
            }

            return $customer;
        });
    }
}
