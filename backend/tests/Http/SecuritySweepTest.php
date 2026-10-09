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
]);

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
