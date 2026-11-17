<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Controllers\Dashboard;

use App\Modules\Expenses\Actions\RecordExpense;
use App\Modules\Expenses\Data\ExpenseData;
use App\Modules\Expenses\Data\ExpenseFiltersData;
use App\Modules\Expenses\Http\Requests\ListExpensesRequest;
use App\Modules\Expenses\Http\Requests\RecordExpenseRequest;
use App\Modules\Expenses\Http\Resources\ExpenseResource;
use App\Modules\Expenses\Queries\ExpenseListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class ExpenseController
{
    /** GET /api/dashboard/expenses?from=&to=&category=&voided= — D100; `meta.total_amount` is the live total of the filter */
    public function index(ListExpensesRequest $request, ExpenseListQuery $expenses): AnonymousResourceCollection
    {
        $filters = ExpenseFiltersData::fromRequest($request);

        return ExpenseResource::collection($expenses->paginate($filters, $request->perPage()))
            ->additional(['meta' => ['total_amount' => $expenses->liveTotal($filters)]]);
    }

    /** POST /api/dashboard/expenses — D100 a */
    public function store(RecordExpenseRequest $request, RecordExpense $record): JsonResponse
    {
        return (new ExpenseResource($record->handle(ExpenseData::fromRequest($request), $request->actorId())))->response()->setStatusCode(201);
    }
}
