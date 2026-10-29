<?php

declare(strict_types=1);

use App\Modules\Reports\Enums\ReportName;
use App\Modules\Reports\Http\Controllers\Dashboard\Reports\BundlesController;
use App\Modules\Reports\Http\Controllers\Dashboard\Reports\CollectionsReportController;
use App\Modules\Reports\Http\Controllers\Dashboard\Reports\CustomerPatternsController;
use App\Modules\Reports\Http\Controllers\Dashboard\Reports\InventoryAnalyticsController;
use App\Modules\Reports\Http\Controllers\Dashboard\Reports\LowStockController;
use App\Modules\Reports\Http\Controllers\Dashboard\Reports\OutstandingController;
use App\Modules\Reports\Http\Controllers\Dashboard\Reports\OverviewController;
use App\Modules\Reports\Http\Controllers\Dashboard\Reports\ProductPerformanceController;
use App\Modules\Reports\Http\Controllers\Dashboard\Reports\ReportExportController;
use App\Modules\Reports\Http\Controllers\Dashboard\Reports\SalesAnalyticsController;
use App\Modules\Reports\Http\Controllers\Dashboard\Reports\SalesController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard reports (08-api-contract.md §11, D5; the advanced reports of V2 Task 16, D94): read-only, every figure
| scoped to the tenant.
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user', 'permission:reports.view'])->prefix('reports')->name('reports.')->group(function (): void {
    Route::get('overview', [OverviewController::class, 'show'])->name('overview');
    Route::get('sales', [SalesController::class, 'show'])->name('sales');
    Route::get('outstanding', [OutstandingController::class, 'show'])->name('outstanding');
    Route::get('low-stock', [LowStockController::class, 'show'])->name('low-stock');
    Route::get('bundles', [BundlesController::class, 'show'])->name('bundles');
    Route::get('sales-analytics', [SalesAnalyticsController::class, 'show'])->name('sales-analytics');
    Route::get('products', [ProductPerformanceController::class, 'show'])->name('products');
    Route::get('customers', [CustomerPatternsController::class, 'show'])->name('customers');
    Route::get('inventory', [InventoryAnalyticsController::class, 'show'])->name('inventory');
    Route::get('collections', [CollectionsReportController::class, 'show'])->name('collections');
    Route::get('{report}/export', [ReportExportController::class, 'show'])->whereIn('report', array_column(ReportName::cases(), 'value'))->name('export');
});
