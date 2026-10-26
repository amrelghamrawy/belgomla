<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Data\CustomerGroupData;
use App\Modules\Customers\Exceptions\CustomerPricingRefused;
use App\Modules\Customers\Models\CustomerGroup;

/**
 * BR-PR12 (V2 Task 11, D89): the merchant defines a wholesale level with one % off the base price (0–90). The name is
 * unique in the store (`409 duplicate_name`). Audited as `customer_group.created`.
 */
final readonly class CreateCustomerGroup
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(CustomerGroupData $data): CustomerGroup
    {
        $name = (string) $data->name;

        if (CustomerGroup::query()->where('name', $name)->exists()) {
            throw CustomerPricingRefused::duplicateGroupName();
        }

        $group = CustomerGroup::query()->create([
            'name' => $name,
            'discount_percent' => $data->discountPercent ?? '0.00',
            'sort_order' => $data->sortOrder ?? 0,
        ])->refresh();

        $this->audit->log('customer_group.created', $group, null, [
            'name' => $group->name,
            'discount_percent' => $group->discount_percent,
            'sort_order' => $group->sort_order,
        ]);

        return $group;
    }
}
