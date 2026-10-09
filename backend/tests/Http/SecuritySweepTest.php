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
