<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Controllers\Dashboard;

use App\Modules\Expenses\Actions\VoidExpense;
use App\Modules\Expenses\Http\Requests\VoidExpenseRequest;
use App\Modules\Expenses\Http\Resources\ExpenseResource;
use App\Modules\Expenses\Models\Expense;

final readonly class ExpenseVoidController
{
    /** POST /api/dashboard/expenses/{id}/void — D100 c */
    public function store(VoidExpenseRequest $request, Expense $expense, VoidExpense $void): ExpenseResource
    {
        return new ExpenseResource($void->handle($expense, $request->reason(), $request->actorId()));
    }
}
