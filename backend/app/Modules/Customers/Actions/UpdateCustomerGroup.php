<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Data\CustomerGroupData;
use App\Modules\Customers\Exceptions\CustomerPricingRefused;
use App\Modules\Customers\Models\CustomerGroup;

/**
 * BR-PR12 (V2 Task 11, D89): the merchant renames a group, changes its % or its place in the list, or brings an archived
 * group back (`status: active`). A field left out stays. A new % applies to the group's customers from their next price
 * (cart, checkout, quote); orders already placed keep their snapshot (BR-PR07). Audited as `customer_group.updated` with
 * what changed; a PATCH that changes nothing writes nothing.
 */
final readonly class UpdateCustomerGroup
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(CustomerGroup $group, CustomerGroupData $data): CustomerGroup
    {
        if ($data->name !== null && $data->name !== $group->name && CustomerGroup::query()->where('name', $data->name)->whereKeyNot($group->id)->exists()) {
            throw CustomerPricingRefused::duplicateGroupName();
        }

        $old = $this->values($group);

        $group->fill(array_filter([
            'name' => $data->name,
            'discount_percent' => $data->discountPercent,
            'sort_order' => $data->sortOrder,
            'status' => $data->status,
        ], fn (mixed $value): bool => $value !== null));

        if (! $group->isDirty()) {
            return $group;
        }

        $group->save();
        $this->audit->log('customer_group.updated', $group, $old, $this->values($group->refresh()));

        return $group;
    }

    /**
     * @return array{name: string, discount_percent: string, sort_order: int, status: string}
     */
    private function values(CustomerGroup $group): array
    {
        return ['name' => $group->name, 'discount_percent' => $group->discount_percent, 'sort_order' => $group->sort_order, 'status' => $group->status->value];
    }
}
