<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Enums\TenantStatus;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    $this->customer = $this->makeCustomer(['phone' => '+201012345678', 'password' => 'secret123', 'name' => 'محمود']);
});

function customerLogin(string $phone = '01012345678', string $password = 'secret123', ?App\Modules\Tenancy\Models\Tenant $tenant = null): Illuminate\Testing\TestResponse
{
    return test()->tenantJson('POST', '/api/store/auth/login', ['phone' => $phone, 'password' => $password], $tenant);
}

test('BR-CU04 an active customer logs in with phone and password and reads their profile', function (): void {
    customerLogin()
        ->assertOk()
        ->assertExactJsonStructure(['data' => ['id', 'name', 'phone', 'status', 'status_label', 'phone_verified_at', 'created_at']])
        ->assertJsonPath('data.phone', '+201012345678')
        ->assertJsonPath('data.status', 'active');

    $this->tenantJson('GET', '/api/store/me')
        ->assertOk()
        ->assertJsonPath('data.id', $this->customer->id)
        ->assertJsonPath('data.name', 'محمود');
});

test('the phone can be typed in any spelling', function (string $phone): void {
    customerLogin($phone)->assertOk();
})->with(['international' => ['+20 101 234 5678'], 'arabic digits' => ['٠١٠١٢٣٤٥٦٧٨'], 'without zero' => ['1012345678']]);

test('a wrong password is refused without saying which field failed', function (): void {
    customerLogin(password: 'wrong-pass')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_credentials');
});

test('an unknown phone is refused like a wrong password', function (): void {
    customerLogin('01112345678')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_credentials');
});

test('BR-CU05 only active customers log in; the others learn their status once the password matched', function (CustomerStatus $status, string $code): void {
    Customer::withoutTenantScope()->whereKey($this->customer->id)->update(['status' => $status]);

    customerLogin()
        ->assertForbidden()
        ->assertJsonPath('error.code', $code);

    $this->tenantJson('GET', '/api/store/me')->assertUnauthorized();
})->with([
    'pending approval' => [CustomerStatus::PendingApproval, 'pending_approval'],
    'blocked' => [CustomerStatus::Blocked, 'blocked'],
    'pending verification' => [CustomerStatus::PendingVerification, 'phone_not_verified'],
]);

test('BR-CU08 an invited customer has no password yet and cannot log in', function (): void {
    $this->makeCustomer(['phone' => '+201112345678', 'status' => CustomerStatus::Invited, 'password' => null]);

    customerLogin('01112345678', 'anything1')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_credentials');
});

test('BR-CU01 a customer of another tenant cannot log in on this host', function (): void {
    $this->makeCustomer(['phone' => '+201112345678', 'password' => 'secret123'], $this->otherTenant());

    customerLogin('01112345678')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_credentials');
});

test('Tech §6 a customer session from one tenant host is rejected and destroyed on another host', function (): void {
    $other = $this->otherTenant();
    customerLogin()->assertOk();

    $this->tenantJson('GET', '/api/store/me', tenant: $other)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');

    $this->tenantJson('GET', '/api/store/me')->assertUnauthorized();
});

test('five failed attempts lock the phone for a minute', function (): void {
    RateLimiter::clear('store-login:'.$this->tenant->id.':+201012345678|127.0.0.1');

    customerLogin(password: 'wrong-1')->assertUnprocessable();
    customerLogin(password: 'wrong-2')->assertUnprocessable();
    customerLogin(password: 'wrong-3')->assertUnprocessable();
    customerLogin(password: 'wrong-4')->assertUnprocessable();
    customerLogin(password: 'wrong-5')->assertUnprocessable();

    customerLogin()
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited');

    $this->travel(61)->seconds();
    customerLogin()->assertOk();
});

test('login validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/store/auth/login', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing phone' => [['password' => 'secret123'], 'phone'],
    'invalid phone' => [['phone' => '0100', 'password' => 'secret123'], 'phone'],
    'missing password' => [['phone' => '01012345678'], 'password'],
]);

test('a request without a stateful origin cannot log in', function (): void {
    $this->json('POST', 'http://alnoor.gomal.test/api/store/auth/login', ['phone' => '01012345678', 'password' => 'secret123'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'stateful_request_required');
});

test('BR-T07 a suspended tenant refuses customer login with 503', function (): void {
    $this->tenant->update(['status' => TenantStatus::Suspended]);

    customerLogin()
        ->assertServiceUnavailable()
        ->assertJsonPath('error.code', 'store_unavailable');
});

test('logout ends the customer session', function (): void {
    customerLogin()->assertOk();

    $this->tenantJson('POST', '/api/store/auth/logout')->assertNoContent();

    $this->tenantJson('GET', '/api/store/me')->assertUnauthorized();
});

test('a guest has no profile', function (): void {
    $this->tenantJson('GET', '/api/store/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('a dashboard session does not make a storefront customer', function (): void {
    $this->actingAsOwner();

    $this->tenantJson('GET', '/api/store/me')->assertUnauthorized();
});
