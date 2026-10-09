<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\Store\AddressController;
use App\Modules\Customers\Http\Controllers\Store\Auth\InvitationController;
use App\Modules\Customers\Http\Controllers\Store\Auth\OtpResendController;
use App\Modules\Customers\Http\Controllers\Store\Auth\OtpVerificationController;
use App\Modules\Customers\Http\Controllers\Store\Auth\PasswordForgotController;
use App\Modules\Customers\Http\Controllers\Store\Auth\PasswordResetController;
use App\Modules\Customers\Http\Controllers\Store\Auth\RegisterController;
use App\Modules\Customers\Http\Controllers\Store\Auth\SessionController;
use App\Modules\Customers\Http\Controllers\Store\CartController;
use App\Modules\Customers\Http\Controllers\Store\CartItemController;
use App\Modules\Customers\Http\Controllers\Store\MeController;
use Illuminate\Support\Facades\Route;

/*
| Storefront customers (08-api-contract.md §2, §3). Mounted under /api/store with the tenant middleware.
| Auth endpoints are throttled per IP here; per phone in the actions (BR-CU07). The cart and addresses
| need an active customer (BR-CU05); {address} binds tenant-scoped and the policy hides other customers' rows.
*/

Route::prefix('auth')->name('auth.')->middleware('throttle:20,1')->group(function (): void {
    Route::post('register', [RegisterController::class, 'store'])->name('register');
    Route::post('verify-otp', [OtpVerificationController::class, 'store'])->name('verify-otp');
    Route::post('resend-otp', [OtpResendController::class, 'store'])->name('resend-otp');
    Route::post('login', [SessionController::class, 'store'])->name('login');
    Route::post('forgot-password', [PasswordForgotController::class, 'store'])->name('forgot-password');
    Route::post('reset-password', [PasswordResetController::class, 'store'])->name('reset-password');
    Route::get('invitation/{token}', [InvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->name('invitation.show');
    Route::post('invitation/{token}', [InvitationController::class, 'store'])->where('token', '[A-Za-z0-9]{40}')->name('invitation.activate');
});

// D53: a guest adding to the cart while prices are hidden is told so (403) before auth answers 401.
Route::post('cart/items', [CartItemController::class, 'store'])
    ->middleware(['prices.visible', 'auth:customer', 'session.tenant:customer', 'customer.active'])
    ->name('cart.items.store');

Route::middleware(['auth:customer', 'session.tenant:customer'])->group(function (): void {
    Route::post('auth/logout', [SessionController::class, 'destroy'])->name('auth.logout');
    Route::get('me', [MeController::class, 'show'])->name('me');

    Route::middleware('customer.active')->group(function (): void {
        Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
        Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
        Route::put('addresses/{address}', [AddressController::class, 'update'])->whereNumber('address')->name('addresses.update');
        Route::delete('addresses/{address}', [AddressController::class, 'destroy'])->whereNumber('address')->name('addresses.destroy');

        Route::get('cart', [CartController::class, 'show'])->name('cart.show');
        Route::delete('cart', [CartController::class, 'destroy'])->name('cart.destroy');
        Route::patch('cart/items/{sku}', [CartItemController::class, 'update'])->whereNumber('sku')->name('cart.items.update');
        Route::delete('cart/items/{sku}', [CartItemController::class, 'destroy'])->whereNumber('sku')->name('cart.items.destroy');
    });
});
