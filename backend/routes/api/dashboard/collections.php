<?php

declare(strict_types=1);

use App\Modules\Collections\Http\Controllers\Dashboard\CollectionController;
use App\Modules\Collections\Http\Controllers\Dashboard\CollectionVoidController;
use App\Modules\Collections\Http\Controllers\Dashboard\CustomerStatementController;
use App\Modules\Collections\Http\Controllers\Dashboard\OrderCollectionController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard collections (08-api-contract.md §10, BR-C02..C04). {order} is the per-tenant order number (BR-O09);
| {collection} and {customer} bind tenant-scoped rows, so another tenant's id is 404 (BR-T04). Recording and
| voiding honor `Idempotency-Key` (§0), like the other `POST /orders/{number}/…` writes.
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->group(function (): void {
    Route::get('collections', [CollectionController::class, 'index'])->name('collections.index');
    Route::post('orders/{order}/collections', [OrderCollectionController::class, 'store'])->whereNumber('order')->middleware('idempotent')->name('orders.collections.store');
    Route::post('collections/{collection}/void', [CollectionVoidController::class, 'store'])->whereNumber('collection')->middleware('idempotent')->name('collections.void');
    Route::get('customers/{customer}/statement', [CustomerStatementController::class, 'show'])->whereNumber('customer')->name('customers.statement');
});
