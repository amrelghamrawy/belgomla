<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\Dashboard\BulkStockAdjustmentController;
use App\Modules\Inventory\Http\Controllers\Dashboard\InventoryController;
use App\Modules\Inventory\Http\Controllers\Dashboard\MovementsController;
use App\Modules\Inventory\Http\Controllers\Dashboard\StockAdjustmentController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard inventory (08-api-contract.md §7). Mounted under /api/dashboard with the tenant middleware.
| {sku} binds Catalog's Sku, scoped to the tenant (BR-T04).
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->group(function (): void {
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('inventory/bulk-adjust', [BulkStockAdjustmentController::class, 'store'])->name('inventory.bulk-adjust');
    Route::get('inventory/{sku}', [InventoryController::class, 'show'])->whereNumber('sku')->name('inventory.show');
    Route::get('inventory/{sku}/movements', [MovementsController::class, 'index'])->whereNumber('sku')->name('inventory.movements.index');
    Route::post('inventory/{sku}/adjust', [StockAdjustmentController::class, 'store'])->whereNumber('sku')->name('inventory.adjust');
});
