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
|   routes/api/webhooks.php            -> /api/webhooks/...   (platform host hooks.{base_domain}, provider secrets)
|   routes/api/testing.php             -> /api/_test/...      (local + testing environments only, D62)
|
*/

Route::domain('hooks.'.config()->string('tenancy.base_domain'))->prefix('webhooks')->name('webhooks.')->group(function (): void {
    require __DIR__.'/api/webhooks.php';
});

Route::prefix('store')->name('store.')->middleware(['tenant', 'tenant.active:store'])->group(function (): void {
    require __DIR__.'/api/store/tenancy.php';
    require __DIR__.'/api/store/catalog.php';
    require __DIR__.'/api/store/customers.php';
    require __DIR__.'/api/store/orders.php';
});

Route::prefix('dashboard')->name('dashboard.')->middleware(['tenant', 'tenant.active:dashboard'])->group(function (): void {
    require __DIR__.'/api/dashboard/tenancy.php';
    require __DIR__.'/api/dashboard/catalog.php';
    require __DIR__.'/api/dashboard/pricing.php';
    require __DIR__.'/api/dashboard/inventory.php';
    require __DIR__.'/api/dashboard/customers.php';
    require __DIR__.'/api/dashboard/orders.php';
    require __DIR__.'/api/dashboard/collections.php';
    require __DIR__.'/api/dashboard/reports.php';
    require __DIR__.'/api/dashboard/audit.php';
    require __DIR__.'/api/dashboard/messaging.php';
});

if (app()->environment('local', 'testing')) {
    Route::prefix('_test')->name('testing.')->middleware(['tenant'])->group(function (): void {
        require __DIR__.'/api/testing.php';
    });
}
