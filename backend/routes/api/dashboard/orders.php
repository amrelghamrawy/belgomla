<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\Dashboard\NotificationController;
use App\Modules\Orders\Http\Controllers\Dashboard\NotificationReadController;
use App\Modules\Orders\Http\Controllers\Dashboard\OrderCancelController;
use App\Modules\Orders\Http\Controllers\Dashboard\OrderController;
use App\Modules\Orders\Http\Controllers\Dashboard\OrderDeliveryAddressController;
use App\Modules\Orders\Http\Controllers\Dashboard\OrderTransitionController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard orders and notifications (08-api-contract.md §9, §12). Orders are addressed by their per-tenant
| number (BR-O09); the `POST …/{number}/…` state changes honor `Idempotency-Key` (§0).
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->group(function (): void {
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
    Route::post('orders/{order}/transition', [OrderTransitionController::class, 'store'])->whereNumber('order')->middleware('idempotent')->name('orders.transition');
    Route::post('orders/{order}/cancel', [OrderCancelController::class, 'store'])->whereNumber('order')->middleware('idempotent')->name('orders.cancel');
    Route::put('orders/{order}/delivery-address', [OrderDeliveryAddressController::class, 'update'])->whereNumber('order')->name('orders.delivery-address');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read', [NotificationReadController::class, 'store'])->name('notifications.read');
});
