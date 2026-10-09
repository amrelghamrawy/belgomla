<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Controllers\Dashboard;

use App\Modules\Collections\Http\Requests\CustomerStatementRequest;
use App\Modules\Collections\Http\Resources\StatementOrderResource;
use App\Modules\Collections\Http\Resources\StatementResource;
use App\Modules\Collections\Queries\CustomerStatementQuery;
use App\Modules\Customers\Models\Customer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class CustomerStatementController
{
    /** GET /api/dashboard/customers/{id}/statement — D24, D59: the orders page plus `customer` and `summary` */
    public function show(CustomerStatementRequest $request, Customer $customer, CustomerStatementQuery $statement): AnonymousResourceCollection
    {
        return StatementOrderResource::collection($statement->orders($customer, $request->perPage()))
            ->additional(new StatementResource($customer, $statement->summary($customer))->resolve($request));
    }
}
