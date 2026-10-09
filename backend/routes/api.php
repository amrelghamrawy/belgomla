<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Tenant is resolved from the Host. Each module registers one file per surface:
|   routes/api/store/<module>.php      -> /api/store/...      (public + customer guard)
|   routes/api/dashboard/<module>.php  -> /api/dashboard/...  (tenant_user guard)
|   routes/api/webhooks.php            -> /api/webhooks/...   (provider signatures)
|
*/

Route::prefix('store')->name('store.')->middleware(['tenant', 'tenant.active:store'])->group(function (): void {
    require __DIR__.'/api/store/tenancy.php';
    require __DIR__.'/api/store/catalog.php';
});

Route::prefix('dashboard')->name('dashboard.')->middleware(['tenant', 'tenant.active:dashboard'])->group(function (): void {
    require __DIR__.'/api/dashboard/tenancy.php';
    require __DIR__.'/api/dashboard/catalog.php';
    require __DIR__.'/api/dashboard/pricing.php';
    require __DIR__.'/api/dashboard/inventory.php';
});
