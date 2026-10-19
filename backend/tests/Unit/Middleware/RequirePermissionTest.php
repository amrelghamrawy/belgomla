<?php

declare(strict_types=1);

use App\Modules\Tenancy\Enums\TenantUserRole;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware(['tenant', 'auth:tenant_user', 'permission:orders.manage'])->get('/api/_manage', fn (): string => 'ok');
    Route::middleware(['tenant', 'auth:tenant_user', 'permission:customers.manage,customers.credit'])->get('/api/_either', fn (): string => 'ok');
    // Listed before auth on purpose: the priority list must still run the permission check first.
    Route::middleware(['tenant', Illuminate\Routing\Middleware\SubstituteBindings::class, 'auth:tenant_user', 'permission:settings.manage'])
        ->get('/api/_hidden/{order}', fn (App\Modules\Orders\Models\Order $order): string => 'ok');
});

test('D82 a role with the permission passes', function (): void {
    $this->actingAsOwner($this->makeOwner(attributes: ['role' => TenantUserRole::Sales]));

    $this->tenantJson('GET', '/api/_manage')->assertOk();
});

test('D82 a role without it is refused with 403 forbidden', function (): void {
    $this->actingAsOwner($this->makeOwner(attributes: ['role' => TenantUserRole::Accountant]));

    $this->tenantJson('GET', '/api/_manage')
        ->assertForbidden()
        ->assertExactJson(['error' => ['code' => 'forbidden', 'message' => 'ليس لديك صلاحية لهذا الإجراء']]);
});

test('D82 several permissions mean any one of them', function (TenantUserRole $role, int $status): void {
    $this->actingAsOwner($this->makeOwner(attributes: ['role' => $role]));

    $this->tenantJson('GET', '/api/_either')->assertStatus($status);
})->with([
    'sales (customers.manage)' => [TenantUserRole::Sales, 200],
    'accountant (customers.credit)' => [TenantUserRole::Accountant, 200],
    'warehouse (neither)' => [TenantUserRole::Warehouse, 403],
]);

test('a guest is refused by auth with 401 first', function (): void {
    $this->tenantJson('GET', '/api/_manage')->assertUnauthorized();
});

test('D82 the permission is checked before route binding, so a forbidden role learns nothing from a 404', function (): void {
    $this->actingAsOwner($this->makeOwner(attributes: ['role' => TenantUserRole::Manager]));

    $this->tenantJson('GET', '/api/_hidden/999999')->assertForbidden();

    $this->actingAsOwner();
    $this->tenantJson('GET', '/api/_hidden/999999')->assertNotFound();
});
