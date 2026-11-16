<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\Dashboard\BulkStockAdjustmentController;
use App\Modules\Inventory\Http\Controllers\Dashboard\InventoryController;
use App\Modules\Inventory\Http\Controllers\Dashboard\MovementsController;
use App\Modules\Inventory\Http\Controllers\Dashboard\StockAdjustmentController;
use App\Modules\Inventory\Http\Controllers\Dashboard\StockCostController;
use App\Modules\Inventory\Http\Controllers\Dashboard\StockReceiptController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard inventory (08-api-contract.md §7). Mounted under /api/dashboard with the tenant middleware.
| {sku} binds Catalog's Sku, scoped to the tenant (BR-T04).
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->group(function (): void {
    Route::get('inventory', [InventoryController::class, 'index'])->middleware('permission:orders.view')->name('inventory.index');
    Route::post('inventory/bulk-adjust', [BulkStockAdjustmentController::class, 'store'])->middleware('permission:inventory.adjust')->name('inventory.bulk-adjust');
    Route::get('inventory/{sku}', [InventoryController::class, 'show'])->whereNumber('sku')->middleware('permission:orders.view')->name('inventory.show');
    Route::get('inventory/{sku}/movements', [MovementsController::class, 'index'])->whereNumber('sku')->middleware('permission:orders.view')->name('inventory.movements.index');
    Route::post('inventory/{sku}/adjust', [StockAdjustmentController::class, 'store'])->whereNumber('sku')->middleware('permission:inventory.adjust')->name('inventory.adjust');
    Route::post('inventory/{sku}/receive', [StockReceiptController::class, 'store'])->whereNumber('sku')->middleware(['permission:inventory.adjust', 'idempotent'])->name('inventory.receive'); // BR-INV09
    Route::put('inventory/{sku}/cost', [StockCostController::class, 'update'])->whereNumber('sku')->middleware('permission:inventory.adjust')->name('inventory.cost'); // D96 d
});
