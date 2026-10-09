<?php

declare(strict_types=1);

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Http\Middleware\EnsureTenantActive;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/api/_store', fn (): string => 'ok')->middleware([ResolveTenant::class, EnsureTenantActive::class.':store']);
    Route::get('/api/_dash', fn (): string => 'ok')->middleware([ResolveTenant::class, EnsureTenantActive::class.':dashboard']);
    Route::get('/api/_default', fn (): string => 'ok')->middleware([ResolveTenant::class, EnsureTenantActive::class]);
});

test('an active tenant passes on every surface', function (): void {
    $this->tenantJson('GET', '/api/_store')->assertOk();
    $this->tenantJson('GET', '/api/_dash')->assertOk();
    $this->tenantJson('GET', '/api/_default')->assertOk();
});

test('BR-T07 a suspended tenant is 503 on the store and 403 on the dashboard', function (): void {
    $this->tenant->update(['status' => TenantStatus::Suspended]);

    $this->tenantJson('GET', '/api/_store')->assertStatus(503)->assertJsonPath('error.code', 'store_unavailable');
    $this->tenantJson('GET', '/api/_default')->assertStatus(503);
    $this->tenantJson('GET', '/api/_dash')->assertForbidden()->assertJsonPath('error.code', 'tenant_suspended');
});
