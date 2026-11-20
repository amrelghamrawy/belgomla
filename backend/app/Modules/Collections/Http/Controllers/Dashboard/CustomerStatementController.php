<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Controllers\Dashboard;

use App\Modules\Collections\Http\Requests\CustomerStatementRequest;
use App\Modules\Collections\Http\Resources\LedgerRowResource;
use App\Modules\Collections\Http\Resources\StatementResource;
use App\Modules\Collections\Queries\CustomerLedgerQuery;
use App\Modules\Collections\Queries\CustomerStatementQuery;
use App\Modules\Customers\Models\Customer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class CustomerStatementController
{
    /** GET /api/dashboard/customers/{id}/statement?from=&to= — BR-C08: the ledger page, opening/closing balance, `customer` and `summary` */
    public function show(CustomerStatementRequest $request, Customer $customer, CustomerLedgerQuery $ledger, CustomerStatementQuery $statement): AnonymousResourceCollection
    {
        return LedgerRowResource::collection($ledger->paginate($customer, $request->from(), $request->to(), $request->perPage()))
            ->additional([
                'meta' => ['opening_balance' => $ledger->openingBalance($customer, $request->from()), 'closing_balance' => $ledger->closingBalance($customer, $request->to())],
                ...new StatementResource($customer, $statement->summary($customer))->resolve($request),
            ]);
    }
}
