<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\InvalidCustomerTransition;
use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * BR-CU05 (07-domain-model.md §11): unblocking restores the status the customer had before the block
 * (`active` when unknown). A customer who is not blocked is `409`. Audited as `customer.unblocked`.
 * Owns its transaction.
 */
final readonly class UnblockCustomer
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Customer $customer): Customer
    {
        if ($customer->status !== CustomerStatus::Blocked) {
            throw InvalidCustomerTransition::notBlocked($customer->status);
        }

        return DB::transaction(function () use ($customer): Customer {
            $restored = $customer->status_before_block ?? CustomerStatus::Active;

            $customer->forceFill([
                'status' => $restored,
                'status_before_block' => null,
                'blocked_at' => null,
                'blocked_reason' => null,
            ])->save();

            $this->audit->log('customer.unblocked', $customer, ['status' => CustomerStatus::Blocked->value], ['status' => $restored->value]);

            return $customer;
        });
    }
}
