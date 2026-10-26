<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Dashboard;

use App\Modules\Customers\Actions\ArchiveCustomerGroup;
use App\Modules\Customers\Http\Resources\CustomerGroupResource;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Customers\Queries\CustomerGroupsQuery;

final readonly class CustomerGroupArchiveController
{
    /** POST /api/dashboard/customer-groups/{group}/archive — BR-PR12, D89: its customers keep the id, the discount stops. */
    public function store(CustomerGroup $group, ArchiveCustomerGroup $archive, CustomerGroupsQuery $groups): CustomerGroupResource
    {
        return new CustomerGroupResource($groups->withCount($archive->handle($group)));
    }
}
