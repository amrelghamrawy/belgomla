<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Exceptions\CustomerPricingRefused;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;

/**
 * BR-PR12 (V2 Task 11, D89): puts one customer in a group (or none, null), from the customer form or a bulk assignment. A
 * group of another store is `422` on `customer_group_id`; an archived group can be kept but not joined (`409
 * group_archived`). Audited as `customer.group_changed` when it changes, under the caller's batch id. A step: it joins the
 * caller's transaction.
 */
final readonly class SetCustomerGroup
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Customer $customer, ?int $groupId, ?string $batchId = null): Customer
    {
        if ($groupId === $customer->customer_group_id) {
            return $customer;
        }

        if ($groupId !== null) {
            $group = CustomerGroup::query()->find($groupId) ?? throw CustomerPricingRefused::unknownGroup();

            if (! $group->isActive()) {
                throw CustomerPricingRefused::groupArchived();
            }
        }

        $old = $customer->customer_group_id;
        $customer->forceFill(['customer_group_id' => $groupId])->save();

        $this->audit->log('customer.group_changed', $customer, ['customer_group_id' => $old], ['customer_group_id' => $groupId], $batchId);

        return $customer;
    }
}
