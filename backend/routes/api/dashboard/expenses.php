<?php

declare(strict_types=1);

use App\Modules\Expenses\Http\Controllers\Dashboard\ExpenseCategoryController;
use App\Modules\Expenses\Http\Controllers\Dashboard\ExpenseController;
use App\Modules\Expenses\Http\Controllers\Dashboard\ExpenseVoidController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard expenses (V2 Task 19, D100), all `expenses.manage`. {expense} binds a tenant-scoped row, so another
| tenant's id is 404 (BR-T04). Recording and voiding honor `Idempotency-Key` (D55).
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user', 'permission:expenses.manage'])->group(function (): void {
    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('expenses/categories', [ExpenseCategoryController::class, 'index'])->name('expenses.categories');
    Route::post('expenses', [ExpenseController::class, 'store'])->middleware('idempotent')->name('expenses.store');
    Route::post('expenses/{expense}/void', [ExpenseVoidController::class, 'store'])->whereNumber('expense')->middleware('idempotent')->name('expenses.void');
});
