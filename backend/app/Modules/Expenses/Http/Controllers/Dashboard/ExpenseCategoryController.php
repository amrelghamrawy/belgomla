<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Controllers\Dashboard;

use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Modules\Expenses\Http\Resources\ExpenseCategoryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class ExpenseCategoryController
{
    /** GET /api/dashboard/expenses/categories — D100 b, in the list's order */
    public function index(): AnonymousResourceCollection
    {
        return ExpenseCategoryResource::collection(ExpenseCategory::cases());
    }
}
