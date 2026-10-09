<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\Dashboard\CustomerApprovalController;
use App\Modules\Customers\Http\Controllers\Dashboard\CustomerBlockController;
use App\Modules\Customers\Http\Controllers\Dashboard\CustomerController;
use App\Modules\Customers\Http\Controllers\Dashboard\CustomerInviteController;
use App\Modules\Customers\Http\Controllers\Dashboard\CustomerUnblockController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard customers (08-api-contract.md §8). Mounted under /api/dashboard with the tenant middleware.
| {customer} binds the tenant-scoped Customer, so another tenant's id is 404 (BR-T04, BR-CU02).
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->group(function (): void {
    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');
    Route::post('customers/{customer}/invite', [CustomerInviteController::class, 'store'])->whereNumber('customer')->name('customers.invite');
    Route::post('customers/{customer}/approve', [CustomerApprovalController::class, 'store'])->whereNumber('customer')->name('customers.approve');
    Route::post('customers/{customer}/block', [CustomerBlockController::class, 'store'])->whereNumber('customer')->name('customers.block');
    Route::post('customers/{customer}/unblock', [CustomerUnblockController::class, 'store'])->whereNumber('customer')->name('customers.unblock');
});
