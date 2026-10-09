<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\InvalidCustomerTransition;
use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * BR-CU05, D2: the merchant approves a verified customer, `pending_approval → active`. Any other status is
 * `409 invalid_transition`. Audited as `customer.approved`. Owns its transaction.
 */
final readonly class ApproveCustomer
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Customer $customer, ?int $actorId = null): Customer
    {
        if ($customer->status !== CustomerStatus::PendingApproval) {
            throw InvalidCustomerTransition::from($customer->status, CustomerStatus::Active);
        }

        return DB::transaction(function () use ($customer, $actorId): Customer {
            $customer->forceFill(['status' => CustomerStatus::Active, 'approved_by' => $actorId])->save();

            $this->audit->log('customer.approved', $customer, ['status' => CustomerStatus::PendingApproval->value], ['status' => CustomerStatus::Active->value]);

            return $customer;
        });
    }
}
