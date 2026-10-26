<?php

declare(strict_types=1);

namespace App\Modules\Customers\Queries;

use App\Modules\Customers\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Collection;

/**
 * BR-PR12 (V2 Task 11): the store's customer groups for the «مستويات العملاء» page and the customer form's select: active
 * first, then by the merchant's order and name, each with how many customers it holds (whatever their status).
 */
final readonly class CustomerGroupsQuery
{
    /**
     * @return Collection<int, CustomerGroup>
     */
    public function get(): Collection
    {
        return CustomerGroup::query()
            ->withCount('customers')
            ->orderBy('status')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function withCount(CustomerGroup $group): CustomerGroup
    {
        return $group->loadCount('customers');
    }
}
