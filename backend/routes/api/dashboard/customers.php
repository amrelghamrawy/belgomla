<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\Dashboard\CustomerApprovalController;
use App\Modules\Customers\Http\Controllers\Dashboard\CustomerBlockController;
use App\Modules\Customers\Http\Controllers\Dashboard\CustomerController;
use App\Modules\Customers\Http\Controllers\Dashboard\CustomerGroupArchiveController;
use App\Modules\Customers\Http\Controllers\Dashboard\CustomerGroupAssignmentController;
use App\Modules\Customers\Http\Controllers\Dashboard\CustomerGroupController;
use App\Modules\Customers\Http\Controllers\Dashboard\CustomerInviteController;
use App\Modules\Customers\Http\Controllers\Dashboard\CustomerPriceController;
use App\Modules\Customers\Http\Controllers\Dashboard\CustomerUnblockController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard customers (08-api-contract.md §8). Mounted under /api/dashboard with the tenant middleware.
| {customer} binds the tenant-scoped Customer, so another tenant's id is 404 (BR-T04, BR-CU02). V2 Task 11: customer groups
| ({group}) and the prices negotiated per customer and SKU ({sku}), both `pricing.customers` (BR-PR12, BR-PR13).
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->group(function (): void {
    Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customers.manage,customers.credit,collections.manage')->name('customers.index');
    Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:customers.manage')->name('customers.store');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->middleware('permission:customers.manage,customers.credit,collections.manage')->name('customers.show');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->middleware('permission:customers.manage,customers.credit')->name('customers.update');
    Route::post('customers/{customer}/invite', [CustomerInviteController::class, 'store'])->whereNumber('customer')->middleware('permission:customers.manage')->name('customers.invite');
    Route::post('customers/{customer}/approve', [CustomerApprovalController::class, 'store'])->whereNumber('customer')->middleware('permission:customers.manage')->name('customers.approve');
    Route::post('customers/{customer}/block', [CustomerBlockController::class, 'store'])->whereNumber('customer')->middleware('permission:customers.manage')->name('customers.block');
    Route::post('customers/{customer}/unblock', [CustomerUnblockController::class, 'store'])->whereNumber('customer')->middleware('permission:customers.manage')->name('customers.unblock');
    Route::get('customer-groups', [CustomerGroupController::class, 'index'])->middleware('permission:pricing.customers')->name('customer-groups.index');
    Route::post('customer-groups', [CustomerGroupController::class, 'store'])->middleware('permission:pricing.customers')->name('customer-groups.store');
    Route::patch('customer-groups/{group}', [CustomerGroupController::class, 'update'])->whereNumber('group')->middleware('permission:pricing.customers')->name('customer-groups.update');
    Route::post('customer-groups/{group}/archive', [CustomerGroupArchiveController::class, 'store'])->whereNumber('group')->middleware('permission:pricing.customers')->name('customer-groups.archive');
    Route::post('customer-groups/{group}/assign', [CustomerGroupAssignmentController::class, 'store'])->whereNumber('group')->middleware('permission:pricing.customers')->name('customer-groups.assign');
    Route::get('customers/{customer}/prices', [CustomerPriceController::class, 'index'])->whereNumber('customer')->middleware('permission:pricing.customers')->name('customers.prices.index');
    Route::put('customers/{customer}/prices/{sku}', [CustomerPriceController::class, 'update'])->whereNumber(['customer', 'sku'])->middleware('permission:pricing.customers')->name('customers.prices.update');
    Route::delete('customers/{customer}/prices/{sku}', [CustomerPriceController::class, 'destroy'])->whereNumber(['customer', 'sku'])->middleware('permission:pricing.customers')->name('customers.prices.destroy');
});
