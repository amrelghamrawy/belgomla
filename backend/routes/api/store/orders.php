<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\Store\CheckoutController;
use App\Modules\Orders\Http\Controllers\Store\OrderCancelController;
use App\Modules\Orders\Http\Controllers\Store\OrderController;
use Illuminate\Support\Facades\Route;

/*
| Storefront orders (08-api-contract.md §3): active customers only (BR-CU05, D51). Orders are addressed by their
| per-tenant number (BR-O09); another customer's order is 404 (OrderPolicy).
*/

Route::middleware(['auth:customer', 'session.tenant:customer', 'customer.active'])->group(function (): void {
    Route::post('checkout', [CheckoutController::class, 'store'])->middleware('idempotent')->name('checkout');
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
    Route::post('orders/{order}/cancel', [OrderCancelController::class, 'store'])->whereNumber('order')->name('orders.cancel');
});
