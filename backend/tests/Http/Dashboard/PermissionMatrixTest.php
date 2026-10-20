<?php

declare(strict_types=1);

use App\Modules\Tenancy\Enums\Permission;
use App\Modules\Tenancy\Enums\TenantUserRole;

/*
| BR-T06, D82: every route of the security sweep's `dashboard authenticated routes` dataset (Http/SecuritySweepTest)
| × every role answers 403 exactly when the role has none of the route's permissions. The permissions per route are
| written out here, apart from the route files, so a route that loses or changes its middleware fails this test, and
| the first test keeps this list in step with the sweep.
| Allowed requests may still fail for other reasons (404 for the missing id, 422 for the empty body): only 403 counts.
*/

const DASHBOARD_ROUTES = [
    'settings show' => ['GET', '/api/dashboard/settings', 'settings.manage'],
    'settings update' => ['PUT', '/api/dashboard/settings', 'settings.manage'],
    'settings logo' => ['POST', '/api/dashboard/settings/logo', 'settings.manage'],
    'store whatsapp show' => ['GET', '/api/dashboard/settings/whatsapp', 'settings.manage'],
    'store whatsapp link' => ['POST', '/api/dashboard/settings/whatsapp', 'settings.manage'],
    'store whatsapp unlink' => ['DELETE', '/api/dashboard/settings/whatsapp', 'settings.manage'],
    'users list' => ['GET', '/api/dashboard/users', 'users.manage'],
    'users create' => ['POST', '/api/dashboard/users', 'users.manage'],
    'users update' => ['PATCH', '/api/dashboard/users/1', 'users.manage'],
    'categories list' => ['GET', '/api/dashboard/categories', 'orders.view'],
    'categories create' => ['POST', '/api/dashboard/categories', 'catalog.manage'],
    'categories reorder' => ['POST', '/api/dashboard/categories/reorder', 'catalog.manage'],
    'categories update' => ['PUT', '/api/dashboard/categories/1', 'catalog.manage'],
    'categories archive' => ['POST', '/api/dashboard/categories/1/archive', 'catalog.manage'],
    'categories restore' => ['POST', '/api/dashboard/categories/1/restore', 'catalog.manage'],
    'units list' => ['GET', '/api/dashboard/units', 'orders.view'],
    'units create' => ['POST', '/api/dashboard/units', 'catalog.manage'],
    'units update' => ['PUT', '/api/dashboard/units/1', 'catalog.manage'],
    'units archive' => ['POST', '/api/dashboard/units/1/archive', 'catalog.manage'],
    'fields list' => ['GET', '/api/dashboard/fields', 'orders.view'],
    'fields create' => ['POST', '/api/dashboard/fields', 'catalog.manage'],
    'fields update' => ['PUT', '/api/dashboard/fields/1', 'catalog.manage'],
    'fields archive' => ['POST', '/api/dashboard/fields/1/archive', 'catalog.manage'],
    'field options create' => ['POST', '/api/dashboard/fields/1/options', 'catalog.manage'],
    'field options update' => ['PUT', '/api/dashboard/fields/1/options/1', 'catalog.manage'],
    'field options archive' => ['POST', '/api/dashboard/fields/1/options/1/archive', 'catalog.manage'],
    'products list' => ['GET', '/api/dashboard/products', 'orders.view'],
    'products create' => ['POST', '/api/dashboard/products', 'catalog.manage'],
    'products bulk status' => ['POST', '/api/dashboard/products/bulk/status', 'catalog.manage'],
    'products export' => ['GET', '/api/dashboard/products/export', 'catalog.manage'],
    'products export download' => ['GET', '/api/dashboard/products/export/7a1c6a4e-5a0e-4d8e-9c1e-2b9b3f0a1d11', 'catalog.manage'],
    'products import template' => ['GET', '/api/dashboard/products/import/template', 'catalog.manage'],
    'products import preview' => ['POST', '/api/dashboard/products/import/preview', 'catalog.manage'],
    'products import' => ['POST', '/api/dashboard/products/import', 'catalog.manage'],
    'imports show' => ['GET', '/api/dashboard/imports/1', 'catalog.manage'],
    'products show' => ['GET', '/api/dashboard/products/1', 'orders.view'],
    'products update' => ['PUT', '/api/dashboard/products/1', 'catalog.manage'],
    'products status' => ['PATCH', '/api/dashboard/products/1/status', 'catalog.manage'],
    'products duplicate' => ['POST', '/api/dashboard/products/1/duplicate', 'catalog.manage'],
    'product images upload' => ['POST', '/api/dashboard/products/1/images', 'catalog.manage'],
    'product images reorder' => ['POST', '/api/dashboard/products/1/images/reorder', 'catalog.manage'],
    'product images delete' => ['DELETE', '/api/dashboard/products/1/images/1', 'catalog.manage'],
    'product relations list' => ['GET', '/api/dashboard/products/1/relations', 'orders.view'],
    'product relations add' => ['POST', '/api/dashboard/products/1/relations', 'catalog.manage'],
    'product relations delete' => ['DELETE', '/api/dashboard/products/1/relations/1', 'catalog.manage'],
    'product pricing update' => ['PUT', '/api/dashboard/products/1/pricing', 'pricing.manage'],
    'product pricing recompute' => ['POST', '/api/dashboard/products/1/pricing/recompute', 'pricing.manage'],
    'bulk pricing apply' => ['POST', '/api/dashboard/pricing/bulk', 'pricing.manage'],
    'bulk pricing preview' => ['POST', '/api/dashboard/pricing/bulk/preview', 'pricing.manage'],
    'inventory list' => ['GET', '/api/dashboard/inventory', 'orders.view'],
    'inventory show' => ['GET', '/api/dashboard/inventory/1', 'orders.view'],
    'inventory movements' => ['GET', '/api/dashboard/inventory/1/movements', 'orders.view'],
    'inventory adjust' => ['POST', '/api/dashboard/inventory/1/adjust', 'inventory.adjust'],
    'inventory bulk adjust' => ['POST', '/api/dashboard/inventory/bulk-adjust', 'inventory.adjust'],
    'customers list' => ['GET', '/api/dashboard/customers', 'customers.manage,customers.credit,collections.manage'],
    'customers create' => ['POST', '/api/dashboard/customers', 'customers.manage'],
    'customers show' => ['GET', '/api/dashboard/customers/1', 'customers.manage,customers.credit,collections.manage'],
    'customers update' => ['PUT', '/api/dashboard/customers/1', 'customers.manage,customers.credit'],
    'customers invite' => ['POST', '/api/dashboard/customers/1/invite', 'customers.manage'],
    'customers approve' => ['POST', '/api/dashboard/customers/1/approve', 'customers.manage'],
    'customers block' => ['POST', '/api/dashboard/customers/1/block', 'customers.manage'],
    'customers unblock' => ['POST', '/api/dashboard/customers/1/unblock', 'customers.manage'],
    'orders list' => ['GET', '/api/dashboard/orders', 'orders.view'],
    'orders show' => ['GET', '/api/dashboard/orders/1', 'orders.view'],
    'orders create' => ['POST', '/api/dashboard/orders', 'orders.create'],
    'orders quote' => ['POST', '/api/dashboard/orders/quote', 'orders.create'],
    'orders transition' => ['POST', '/api/dashboard/orders/1/transition', 'orders.manage'],
    'orders cancel' => ['POST', '/api/dashboard/orders/1/cancel', 'orders.manage'],
    'orders delivery address' => ['PUT', '/api/dashboard/orders/1/delivery-address', 'orders.manage'],
    'orders discount' => ['PUT', '/api/dashboard/orders/1/discount', 'orders.discount'],
    'orders lines' => ['PUT', '/api/dashboard/orders/1/lines', 'orders.edit'],
    'order returns list' => ['GET', '/api/dashboard/orders/1/returns', 'orders.view'],
    'order returns record' => ['POST', '/api/dashboard/orders/1/returns', 'orders.returns'],
    'returns list' => ['GET', '/api/dashboard/returns', 'orders.view'],
    'returns void' => ['POST', '/api/dashboard/returns/1/void', 'orders.returns'],
    'notifications list' => ['GET', '/api/dashboard/notifications', 'orders.view'],
    'notifications read' => ['POST', '/api/dashboard/notifications/read', 'orders.view'],
    'collections list' => ['GET', '/api/dashboard/collections', 'collections.manage'],
    'collections record' => ['POST', '/api/dashboard/orders/1/collections', 'collections.manage'],
    'collections void' => ['POST', '/api/dashboard/collections/1/void', 'collections.manage'],
    'customer statement' => ['GET', '/api/dashboard/customers/1/statement', 'customers.manage,customers.credit,collections.manage'],
    'customer credits' => ['GET', '/api/dashboard/customers/1/credits', 'customers.manage,customers.credit,collections.manage'],
    'apply credit' => ['POST', '/api/dashboard/orders/1/apply-credit', 'collections.manage'],
    'void credit' => ['POST', '/api/dashboard/credits/1/void', 'collections.manage'],
    'reports overview' => ['GET', '/api/dashboard/reports/overview', 'reports.view'],
    'reports sales' => ['GET', '/api/dashboard/reports/sales', 'reports.view'],
    'reports outstanding' => ['GET', '/api/dashboard/reports/outstanding', 'reports.view'],
    'reports low stock' => ['GET', '/api/dashboard/reports/low-stock', 'reports.view'],
    'audit log' => ['GET', '/api/dashboard/audit', 'audit.view'],
];

dataset('dashboard routes by role', function (): array {
    $rows = [];

    foreach (array_keys(DASHBOARD_ROUTES) as $name) {
        foreach (TenantUserRole::cases() as $role) {
            $rows["{$name} as {$role->value}"] = [$name, $role];
        }
    }

    return $rows;
});

test('D82 every authenticated dashboard route of the security sweep has its permissions listed here', function (string $method, string $uri): void {
    $listed = array_filter(DASHBOARD_ROUTES, fn (array $route): bool => $route[0] === $method && $route[1] === $uri);

    expect($listed !== [] || in_array("{$method} {$uri}", ['GET /api/dashboard/me', 'POST /api/dashboard/auth/logout'], true))->toBeTrue("{$method} {$uri} is missing");
})->with('dashboard authenticated routes');

test('D82 a role without the route\'s permission gets 403, a role with it does not', function (string $name, TenantUserRole $role): void {
    [$method, $uri, $permissions] = DASHBOARD_ROUTES[$name];
    $allowed = array_any(explode(',', $permissions), fn (string $permission): bool => $role->can(Permission::from($permission)));
    $this->actingAsOwner($this->makeOwner(attributes: ['role' => $role]));

    $status = $this->tenantJson($method, $uri)->getStatusCode();

    expect($status === 403)->toBe(! $allowed, "{$method} {$uri} as {$role->value} answered {$status}");
})->with('dashboard routes by role');
