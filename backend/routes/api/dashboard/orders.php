<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\Dashboard\NotificationController;
use App\Modules\Orders\Http\Controllers\Dashboard\NotificationReadController;
use App\Modules\Orders\Http\Controllers\Dashboard\OrderCancelController;
use App\Modules\Orders\Http\Controllers\Dashboard\OrderController;
use App\Modules\Orders\Http\Controllers\Dashboard\OrderDeliveryAddressController;
use App\Modules\Orders\Http\Controllers\Dashboard\OrderReturnController;
use App\Modules\Orders\Http\Controllers\Dashboard\OrderTransitionController;
use App\Modules\Orders\Http\Controllers\Dashboard\ReturnController;
use App\Modules\Orders\Http\Controllers\Dashboard\ReturnVoidController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard orders, returns and notifications (08-api-contract.md §9, §12). Orders are addressed by their per-tenant
| number (BR-O09), returns by their tenant-scoped id (another tenant's is 404, BR-T04); the `POST` state changes
| honor `Idempotency-Key` (§0).
*/

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->group(function (): void {
    Route::get('orders', [OrderController::class, 'index'])->middleware('permission:orders.view')->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->middleware('permission:orders.view')->name('orders.show');
    Route::post('orders/{order}/transition', [OrderTransitionController::class, 'store'])->whereNumber('order')->middleware('idempotent')->middleware('permission:orders.manage')->name('orders.transition');
    Route::post('orders/{order}/cancel', [OrderCancelController::class, 'store'])->whereNumber('order')->middleware('idempotent')->middleware('permission:orders.manage')->name('orders.cancel');
    Route::put('orders/{order}/delivery-address', [OrderDeliveryAddressController::class, 'update'])->whereNumber('order')->middleware('permission:orders.manage')->name('orders.delivery-address');
    Route::get('orders/{order}/returns', [OrderReturnController::class, 'index'])->whereNumber('order')->middleware('permission:orders.view')->name('orders.returns.index');
    Route::post('orders/{order}/returns', [OrderReturnController::class, 'store'])->whereNumber('order')->middleware('idempotent')->middleware('permission:orders.returns')->name('orders.returns.store');
    Route::get('returns', [ReturnController::class, 'index'])->middleware('permission:orders.view')->name('returns.index');
    Route::post('returns/{orderReturn}/void', [ReturnVoidController::class, 'store'])->whereNumber('orderReturn')->middleware('idempotent')->middleware('permission:orders.returns')->name('returns.void');

    Route::get('notifications', [NotificationController::class, 'index'])->middleware('permission:orders.view')->name('notifications.index');
    Route::post('notifications/read', [NotificationReadController::class, 'store'])->middleware('permission:orders.view')->name('notifications.read');
});
