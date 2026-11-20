<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Enums\CustomerGroupStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;

/**
 * BR-PR12 (V2 Task 11, D89): the merchant archives a group, even with customers in it: its discount stops applying at once
 * (PricingContextQuery ignores it), and its customers keep the group id, so restoring it brings their prices back.
 * Archiving an archived group changes nothing. Audited as `customer_group.archived` with how many customers it held.
 */
final readonly class ArchiveCustomerGroup
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(CustomerGroup $group): CustomerGroup
    {
        if ($group->status === CustomerGroupStatus::Archived) {
            return $group;
        }

        $group->forceFill(['status' => CustomerGroupStatus::Archived])->save();

        $this->audit->log('customer_group.archived', $group, ['status' => CustomerGroupStatus::Active->value], [
            'status' => CustomerGroupStatus::Archived->value,
            'customers' => Customer::query()->where('customer_group_id', $group->id)->count(),
        ]);

        return $group;
    }
}
