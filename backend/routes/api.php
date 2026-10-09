<?php

use App\Modules\Tenancy\Http\Controllers\DashboardAuthController;
use App\Modules\Tenancy\Http\Controllers\DashboardSettingsController;
use App\Modules\Tenancy\Http\Controllers\DashboardUsersController;
use App\Modules\Tenancy\Http\Controllers\StoreTenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront  /api/store/...
|--------------------------------------------------------------------------
*/
Route::prefix('store')->middleware(['tenant', 'tenant.active:store'])->group(function () {
    Route::get('tenant', [StoreTenantController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Dashboard  /api/dashboard/...
|--------------------------------------------------------------------------
*/
Route::prefix('dashboard')->middleware(['tenant', 'tenant.active:dashboard'])->group(function () {
    Route::post('auth/login', [DashboardAuthController::class, 'login'])->middleware('throttle:20,1');

    Route::middleware(['auth:tenant_user', 'session.tenant:tenant_user'])->group(function () {
        Route::post('auth/logout', [DashboardAuthController::class, 'logout']);
        Route::get('me', [DashboardAuthController::class, 'me']);

        Route::get('settings', [DashboardSettingsController::class, 'show']);
        Route::put('settings', [DashboardSettingsController::class, 'update']);
        Route::post('settings/{kind}', [DashboardSettingsController::class, 'uploadImage'])
            ->where('kind', 'logo|favicon');

        Route::get('users', [DashboardUsersController::class, 'index']);
        Route::post('users', [DashboardUsersController::class, 'store']);
        Route::patch('users/{id}', [DashboardUsersController::class, 'update'])->whereNumber('id');
    });
});
