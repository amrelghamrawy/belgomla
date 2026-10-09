<?php

declare(strict_types=1);

use App\Modules\Reports\Http\Controllers\Dashboard\Reports\LowStockController;
use App\Modules\Reports\Http\Controllers\Dashboard\Reports\OutstandingController;
use App\Modules\Reports\Http\Controllers\Dashboard\Reports\OverviewController;
use App\Modules\Reports\Http\Controllers\Dashboard\Reports\SalesController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard reports (08-api-contract.md §11, D5): read-only, every figure scoped to the tenant.
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->prefix('reports')->name('reports.')->group(function (): void {
    Route::get('overview', [OverviewController::class, 'show'])->name('overview');
    Route::get('sales', [SalesController::class, 'show'])->name('sales');
    Route::get('outstanding', [OutstandingController::class, 'show'])->name('outstanding');
    Route::get('low-stock', [LowStockController::class, 'show'])->name('low-stock');
});
