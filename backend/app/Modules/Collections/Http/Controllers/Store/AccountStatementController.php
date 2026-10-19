<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Controllers\Store;

use App\Modules\Collections\Http\Requests\CustomerStatementRequest;
use App\Modules\Collections\Http\Resources\LedgerRowResource;
use App\Modules\Collections\Queries\CustomerLedgerQuery;
use App\Modules\Collections\Queries\CustomerStatementQuery;
use App\Modules\Customers\Models\Customer;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class AccountStatementController
{
    /**
     * GET /api/store/account/statement?from=&to= — BR-C08: the signed-in customer's own ledger, the same rows as the
     * merchant's (they carry no internal notes), with what they owe, their credit and what is overdue.
     */
    public function show(CustomerStatementRequest $request, #[CurrentUser('customer')] Customer $customer, CustomerLedgerQuery $ledger, CustomerStatementQuery $statement): AnonymousResourceCollection
    {
        $summary = $statement->summary($customer);

        return LedgerRowResource::collection($ledger->paginate($customer, $request->from(), $request->to(), $request->perPage()))
            ->additional([
                'meta' => ['opening_balance' => $ledger->openingBalance($customer, $request->from()), 'closing_balance' => $ledger->closingBalance($customer, $request->to())],
                'summary' => [
                    'outstanding' => $summary['outstanding'],
                    'credit_limit' => $summary['credit_limit'],
                    'available_credit' => $summary['available_credit'],
                    'credit_balance' => $summary['credit_balance'],
                    'overdue' => $summary['overdue'],
                ],
            ]);
    }
}
