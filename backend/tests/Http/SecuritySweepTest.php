<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Security sweep
|--------------------------------------------------------------------------
|
| Every module appends its routes to these datasets (the dashboard one lives in tests/Datasets, shared with the
| permission matrix). The sweep guarantees that:
|  - a guest is refused on every authenticated dashboard route,
|  - every error is rendered as the JSON envelope, never HTML,
|  - unknown resources are 404, not 403 or 500.
|
*/

dataset('customer authenticated routes', [
    'me' => ['GET', '/api/store/me'],
    'logout' => ['POST', '/api/store/auth/logout'],
    'addresses list' => ['GET', '/api/store/addresses'],
    'addresses create' => ['POST', '/api/store/addresses'],
    'addresses update' => ['PUT', '/api/store/addresses/1'],
    'addresses delete' => ['DELETE', '/api/store/addresses/1'],
    'account statement' => ['GET', '/api/store/account/statement'],
    'account preferences' => ['PATCH', '/api/store/account/preferences'],
    'cart show' => ['GET', '/api/store/cart'],
    'cart clear' => ['DELETE', '/api/store/cart'],
    'cart add' => ['POST', '/api/store/cart/items'],
    'cart update' => ['PATCH', '/api/store/cart/items/1'],
    'cart remove' => ['DELETE', '/api/store/cart/items/1'],
    'checkout' => ['POST', '/api/store/checkout'],
    'preorder checkout' => ['POST', '/api/store/checkout/preorder'],
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
