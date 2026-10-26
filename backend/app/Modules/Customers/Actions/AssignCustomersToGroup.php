<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Data\GroupAssignmentData;
use App\Modules\Customers\Exceptions\CustomerPricingRefused;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Customers\Queries\CustomerListQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/dashboard/customer-groups/{id}/assign (BR-PR12, V2 Task 11, D89): moves customers into an active group (`409
 * group_archived` otherwise), whatever group they were in: the ids ticked on the customers list (an id of another store
 * is `422` on `customer_ids`), or every customer the list's filter selects (CustomerListQuery). Customers already in the
 * group are left alone. Owns its transaction; one `customer_group.assigned` record on the group and one
 * `customer.group_changed` per customer moved, under one batch id. Returns how many moved.
 */
final readonly class AssignCustomersToGroup
{
    public function __construct(
        private CustomerListQuery $customers,
        private SetCustomerGroup $setGroup,
        private AuditLogger $audit,
    ) {}

    public function handle(CustomerGroup $group, GroupAssignmentData $data): int
    {
        if (! $group->isActive()) {
            throw CustomerPricingRefused::groupArchived();
        }

        return DB::transaction(function () use ($group, $data): int {
            $targets = $this->targets($data)->filter(fn (Customer $customer): bool => $customer->customer_group_id !== $group->id)->values();
            $batchId = $this->audit->newBatchId();

            foreach ($targets as $customer) {
                $this->setGroup->handle($customer, $group->id, $batchId);
            }

            $this->audit->log('customer_group.assigned', $group, null, [
                'assigned' => $targets->count(),
                ...($data->customerIds === null
                    ? ['filter' => ['q' => $data->search, 'status' => $data->status?->value, 'group_id' => $data->groupId]]
                    : ['customer_ids' => $targets->modelKeys()]),
            ], $batchId);

            return $targets->count();
        });
    }

    /**
     * @return Collection<int, Customer>
     */
    private function targets(GroupAssignmentData $data): Collection
    {
        if ($data->customerIds === null) {
            return $this->customers->builder($data->search, $data->status, $data->groupId)->orderBy('id')->lockForUpdate()->get();
        }

        $customers = Customer::query()->whereKey($data->customerIds)->orderBy('id')->lockForUpdate()->get();

        if ($customers->count() !== count(array_unique($data->customerIds))) {
            throw CustomerPricingRefused::unknownCustomers();
        }

        return $customers;
    }
}
