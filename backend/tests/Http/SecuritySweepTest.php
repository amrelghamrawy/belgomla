<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Security sweep
|--------------------------------------------------------------------------
|
| Every module appends its routes to these datasets. The sweep guarantees that:
|  - a guest is refused on every authenticated dashboard route,
|  - every error is rendered as the JSON envelope, never HTML,
|  - unknown resources are 404, not 403 or 500.
|
*/

dataset('dashboard authenticated routes', [
    'me' => ['GET', '/api/dashboard/me'],
    'logout' => ['POST', '/api/dashboard/auth/logout'],
    'settings show' => ['GET', '/api/dashboard/settings'],
    'settings update' => ['PUT', '/api/dashboard/settings'],
    'settings logo' => ['POST', '/api/dashboard/settings/logo'],
    'users list' => ['GET', '/api/dashboard/users'],
    'users create' => ['POST', '/api/dashboard/users'],
    'users update' => ['PATCH', '/api/dashboard/users/1'],
    'categories list' => ['GET', '/api/dashboard/categories'],
    'categories create' => ['POST', '/api/dashboard/categories'],
    'categories reorder' => ['POST', '/api/dashboard/categories/reorder'],
    'categories update' => ['PUT', '/api/dashboard/categories/1'],
    'categories archive' => ['POST', '/api/dashboard/categories/1/archive'],
    'categories restore' => ['POST', '/api/dashboard/categories/1/restore'],
    'units list' => ['GET', '/api/dashboard/units'],
    'units create' => ['POST', '/api/dashboard/units'],
    'units update' => ['PUT', '/api/dashboard/units/1'],
    'units archive' => ['POST', '/api/dashboard/units/1/archive'],
    'fields list' => ['GET', '/api/dashboard/fields'],
    'fields create' => ['POST', '/api/dashboard/fields'],
    'fields update' => ['PUT', '/api/dashboard/fields/1'],
    'fields archive' => ['POST', '/api/dashboard/fields/1/archive'],
    'field options create' => ['POST', '/api/dashboard/fields/1/options'],
    'field options update' => ['PUT', '/api/dashboard/fields/1/options/1'],
    'field options archive' => ['POST', '/api/dashboard/fields/1/options/1/archive'],
    'products list' => ['GET', '/api/dashboard/products'],
    'products create' => ['POST', '/api/dashboard/products'],
    'products bulk status' => ['POST', '/api/dashboard/products/bulk/status'],
    'products show' => ['GET', '/api/dashboard/products/1'],
    'products update' => ['PUT', '/api/dashboard/products/1'],
    'products status' => ['PATCH', '/api/dashboard/products/1/status'],
    'products duplicate' => ['POST', '/api/dashboard/products/1/duplicate'],
    'product images upload' => ['POST', '/api/dashboard/products/1/images'],
    'product images reorder' => ['POST', '/api/dashboard/products/1/images/reorder'],
    'product images delete' => ['DELETE', '/api/dashboard/products/1/images/1'],
    'product relations list' => ['GET', '/api/dashboard/products/1/relations'],
    'product relations add' => ['POST', '/api/dashboard/products/1/relations'],
    'product relations delete' => ['DELETE', '/api/dashboard/products/1/relations/1'],
    'product pricing update' => ['PUT', '/api/dashboard/products/1/pricing'],
    'product pricing recompute' => ['POST', '/api/dashboard/products/1/pricing/recompute'],
    'bulk pricing apply' => ['POST', '/api/dashboard/pricing/bulk'],
    'bulk pricing preview' => ['POST', '/api/dashboard/pricing/bulk/preview'],
    'inventory list' => ['GET', '/api/dashboard/inventory'],
    'inventory show' => ['GET', '/api/dashboard/inventory/1'],
    'inventory movements' => ['GET', '/api/dashboard/inventory/1/movements'],
    'inventory adjust' => ['POST', '/api/dashboard/inventory/1/adjust'],
    'inventory bulk adjust' => ['POST', '/api/dashboard/inventory/bulk-adjust'],
    'customers list' => ['GET', '/api/dashboard/customers'],
    'customers create' => ['POST', '/api/dashboard/customers'],
    'customers show' => ['GET', '/api/dashboard/customers/1'],
    'customers update' => ['PUT', '/api/dashboard/customers/1'],
    'customers invite' => ['POST', '/api/dashboard/customers/1/invite'],
    'customers approve' => ['POST', '/api/dashboard/customers/1/approve'],
    'customers block' => ['POST', '/api/dashboard/customers/1/block'],
    'customers unblock' => ['POST', '/api/dashboard/customers/1/unblock'],
    'orders list' => ['GET', '/api/dashboard/orders'],
    'orders show' => ['GET', '/api/dashboard/orders/1'],
    'orders transition' => ['POST', '/api/dashboard/orders/1/transition'],
    'orders cancel' => ['POST', '/api/dashboard/orders/1/cancel'],
    'orders delivery address' => ['PUT', '/api/dashboard/orders/1/delivery-address'],
    'notifications list' => ['GET', '/api/dashboard/notifications'],
    'notifications read' => ['POST', '/api/dashboard/notifications/read'],
]);

dataset('customer authenticated routes', [
    'me' => ['GET', '/api/store/me'],
    'logout' => ['POST', '/api/store/auth/logout'],
    'addresses list' => ['GET', '/api/store/addresses'],
    'addresses create' => ['POST', '/api/store/addresses'],
    'addresses update' => ['PUT', '/api/store/addresses/1'],
    'addresses delete' => ['DELETE', '/api/store/addresses/1'],
    'cart show' => ['GET', '/api/store/cart'],
    'cart clear' => ['DELETE', '/api/store/cart'],
    'cart add' => ['POST', '/api/store/cart/items'],
    'cart update' => ['PATCH', '/api/store/cart/items/1'],
    'cart remove' => ['DELETE', '/api/store/cart/items/1'],
    'checkout' => ['POST', '/api/store/checkout'],
    'orders list' => ['GET', '/api/store/orders'],
    'orders show' => ['GET', '/api/store/orders/1'],
    'orders cancel' => ['POST', '/api/store/orders/1/cancel'],
]);

test('a guest is refused on every customer route of the storefront', function (string $method, string $uri): void {
    $this->tenantJson($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'يجب تسجيل الدخول']]);
})->with('customer authenticated routes');

test('a dashboard user is not a storefront customer on any customer route', function (string $method, string $uri): void {
    $this->actingAsOwner();

    $this->tenantJson($method, $uri)->assertUnauthorized();
})->with('customer authenticated routes');

test('a storefront customer is not a dashboard user on any dashboard route', function (string $method, string $uri): void {
    $this->actingAsCustomer();

    $this->tenantJson($method, $uri)->assertUnauthorized();
})->with('dashboard authenticated routes');

test('a guest is refused on every authenticated dashboard route', function (string $method, string $uri): void {
    $this->tenantJson($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'يجب تسجيل الدخول']]);
})->with('dashboard authenticated routes');

test('every api error is rendered as the json envelope even without an Accept header', function (): void {
    app(App\Modules\Tenancy\TenantContext::class)->clear();

    $this->call('GET', 'http://alnoor.gomal.test/api/dashboard/me')
        ->assertUnauthorized()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('unknown api paths are 404 json, not html', function (): void {
    $this->tenantJson('GET', '/api/does-not-exist')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

test('a csrf mismatch is the csrf_token_mismatch envelope, not a generic http error', function (): void {
    Illuminate\Support\Facades\Route::post('/api/store/_csrf', fn () => throw new Illuminate\Session\TokenMismatchException);

    $this->tenantJson('POST', '/api/store/_csrf')
        ->assertStatus(419)
        ->assertExactJson(['error' => ['code' => 'csrf_token_mismatch', 'message' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة']]);
});

test('a policy that hides a resource renders as the not_found envelope', function (): void {
    Illuminate\Support\Facades\Route::get('/api/store/_hidden', fn () => throw new Illuminate\Auth\Access\AuthorizationException()->withStatus(404));

    $this->tenantJson('GET', '/api/store/_hidden')
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'العنصر غير موجود']]);
});

test('server errors do not leak exception details when debug is off', function (): void {
    config(['app.debug' => false]);
    Illuminate\Support\Facades\Route::get('/api/store/_boom', fn () => throw new RuntimeException('secret detail'));

    $this->tenantJson('GET', '/api/store/_boom')
        ->assertStatus(500)
        ->assertExactJson(['error' => ['code' => 'server_error', 'message' => 'حدث خطأ غير متوقع']]);
});

test('webhook errors on the hooks host are json even without an Accept header', function (): void {
    app(App\Modules\Tenancy\TenantContext::class)->clear();

    $this->call('POST', 'http://hooks.gomal.test/api/webhooks/hypersender/sms')
        ->assertUnauthorized()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('error.code', 'unauthenticated');
});
