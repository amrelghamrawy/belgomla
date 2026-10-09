<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    $this->makeOwner(attributes: ['email' => 'owner@alnoor.test', 'password' => 'secret123']);
});

function login(string $email = 'owner@alnoor.test', string $password = 'secret123', ?Tenant $tenant = null): Illuminate\Testing\TestResponse
{
    return test()->tenantJson('POST', '/api/dashboard/auth/login', ['email' => $email, 'password' => $password], $tenant);
}

test('an owner logs in and reads their profile and tenant', function (): void {
    login()
        ->assertOk()
        ->assertExactJsonStructure(['data' => ['id', 'name', 'email', 'role', 'status', 'last_login_at']])
        ->assertJsonPath('data.email', 'owner@alnoor.test')
        ->assertJsonPath('data.role', 'owner');

    $this->tenantJson('GET', '/api/dashboard/me')
        ->assertOk()
        ->assertJsonPath('data.tenant.subdomain', 'alnoor')
        ->assertJsonPath('data.tenant.vertical', 'school-books')
        ->assertJsonPath('data.user.email', 'owner@alnoor.test');

    expect(TenantUser::withoutTenantScope()->first()?->last_login_at)->not->toBeNull();
});

test('email matching is case-insensitive', function (): void {
    login('Owner@Alnoor.TEST')->assertOk();
});

test('a wrong password is rejected without revealing which field failed', function (): void {
    login(password: 'nope')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_credentials');
});

test('login validates its input', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/auth/login', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing email' => [['password' => 'x'], 'email'],
    'invalid email' => [['email' => 'not-an-email', 'password' => 'x'], 'email'],
    'missing password' => [['email' => 'a@b.c'], 'password'],
]);

test('BR-T03 a user of another tenant cannot log in on this host', function (): void {
    $other = $this->otherTenant();
    $this->makeOwner($other, ['email' => 'owner@other.test', 'password' => 'secret123']);

    login('owner@other.test')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_credentials');
});

test('a session created on one tenant host is rejected and destroyed on another host', function (): void {
    $other = $this->otherTenant();

    login()->assertOk();

    $this->tenantJson('GET', '/api/dashboard/me', tenant: $other)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');

    $this->tenantJson('GET', '/api/dashboard/me')->assertUnauthorized();
});

test('a disabled user cannot log in', function (): void {
    TenantUser::withoutTenantScope()->update(['status' => TenantUser::STATUS_DISABLED]);

    login()
        ->assertForbidden()
        ->assertJsonPath('error.code', 'user_disabled');
});

test('BR-T07 a suspended tenant refuses dashboard login with 403', function (): void {
    $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

    login()
        ->assertForbidden()
        ->assertJsonPath('error.code', 'tenant_suspended');
});

test('a guest is refused on authenticated dashboard routes', function (): void {
    $this->tenantJson('GET', '/api/dashboard/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('the tenant is resolved before authentication, so an unknown host is 404 not 401', function (): void {
    $this->hostJson('nobody.gomal.test', 'GET', '/api/dashboard/settings')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

test('logout ends the session', function (): void {
    login()->assertOk();

    $this->tenantJson('POST', '/api/dashboard/auth/logout')->assertNoContent();
    $this->tenantJson('GET', '/api/dashboard/me')->assertUnauthorized();
});

test('a request without a stateful origin cannot log in', function (): void {
    $this->json('POST', 'http://alnoor.gomal.test/api/dashboard/auth/login', ['email' => 'owner@alnoor.test', 'password' => 'secret123'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'stateful_request_required');
});

test('five failed attempts lock the email for a minute', function (): void {
    RateLimiter::clear('dashboard-login:'.$this->tenant->id.':owner@alnoor.test|127.0.0.1');

    foreach (range(1, 5) as $attempt) {
        login(password: 'wrong')->assertUnprocessable();
    }

    login()
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');
});
