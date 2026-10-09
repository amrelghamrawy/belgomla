<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\Dashboard\BrandingImageController;
use App\Modules\Tenancy\Http\Controllers\Dashboard\MeController;
use App\Modules\Tenancy\Http\Controllers\Dashboard\SessionController;
use App\Modules\Tenancy\Http\Controllers\Dashboard\SettingsController;
use App\Modules\Tenancy\Http\Controllers\Dashboard\UserController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [SessionController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('auth.login');

Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->group(function (): void {
    Route::post('auth/logout', [SessionController::class, 'destroy'])->name('auth.logout');
    Route::get('me', [MeController::class, 'show'])->name('me');

    Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('settings/{kind}', [BrandingImageController::class, 'store'])
        ->whereIn('kind', ['logo', 'favicon'])
        ->name('settings.branding');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::patch('users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');
});
