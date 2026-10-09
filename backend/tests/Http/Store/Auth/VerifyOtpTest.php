<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Tenancy\Models\TenantSetting;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->customer = $this->makeCustomer(['phone' => '+201012345678', 'status' => CustomerStatus::PendingVerification, 'phone_verified_at' => null]);
    $this->inTenant(fn () => CustomerOtp::factory()->code('654321')->create(['phone' => '+201012345678']));
});

function verify(string $code = '654321', string $purpose = 'register'): Illuminate\Testing\TestResponse
{
    return test()->tenantJson('POST', '/api/store/auth/verify-otp', ['phone' => '01012345678', 'code' => $code, 'purpose' => $purpose]);
}

test('BR-CU07 the right code activates the customer, verifies the phone and starts a session', function (): void {
    $this->freezeTime();

    verify()
        ->assertOk()
        ->assertJsonPath('data.id', $this->customer->id)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.phone_verified_at', now()->toIso8601String());

    $this->tenantJson('GET', '/api/store/me')->assertOk()->assertJsonPath('data.status', 'active');
    expect($this->inTenant(fn () => CustomerOtp::query()->sole()->consumed_at))->not->toBeNull();
});

test('D2 with approval required the verified customer waits for the merchant', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['require_customer_approval' => true]);

    verify()
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_approval');

    $this->tenantJson('GET', '/api/store/me')->assertOk()->assertJsonPath('data.status', 'pending_approval');
});

test('BR-CU07 a wrong code is refused and counts an attempt', function (): void {
    verify('111111')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'otp_invalid')
        ->assertJsonPath('error.details.attempts_left', 4);

    expect($this->inTenant(fn () => Customer::query()->sole()->status))->toBe(CustomerStatus::PendingVerification);
});

test('BR-CU07 after five wrong attempts even the right code is refused', function (): void {
    $this->inTenant(fn () => CustomerOtp::query()->update(['attempts' => 5]));

    verify()
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'otp_attempts_exceeded');
});

test('BR-CU07 a code is valid ten minutes', function (): void {
    $this->travel(11)->minutes();

    verify()
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'otp_expired');
});

test('BR-CU07 a code is single use', function (): void {
    verify()->assertOk();

    verify()
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'otp_invalid');
});

test('a phone without a pending registration has nothing to verify', function (): void {
    $this->tenantJson('POST', '/api/store/auth/verify-otp', ['phone' => '01112345678', 'code' => '654321', 'purpose' => 'register'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'otp_invalid');
});

test('BR-T03 a code of the same phone on another tenant does not verify here', function (): void {
    $this->inTenant(fn () => CustomerOtp::query()->delete());
    $other = $this->otherTenant();
    $this->makeCustomer(['phone' => '+201012345678', 'status' => CustomerStatus::PendingVerification], $other);
    $this->inTenant(fn () => CustomerOtp::factory()->code('654321')->create(['phone' => '+201012345678']), $other);

    verify()
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'otp_invalid');
});

test('a password reset code is checked without being used up', function (): void {
    $this->inTenant(fn () => Customer::query()->update(['status' => CustomerStatus::Active]));
    $this->inTenant(fn () => CustomerOtp::factory()->passwordReset()->code('222333')->create(['phone' => '+201012345678']));

    verify('222333', 'password_reset')->assertNoContent();

    expect($this->inTenant(fn () => CustomerOtp::query()->where('purpose', 'password_reset')->sole()->consumed_at))->toBeNull();
});

test('verify-otp validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/store/auth/verify-otp', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing phone' => [['code' => '123456', 'purpose' => 'register'], 'phone'],
    'invalid phone' => [['phone' => '999', 'code' => '123456', 'purpose' => 'register'], 'phone'],
    'missing code' => [['phone' => '01012345678', 'purpose' => 'register'], 'code'],
    'code of 5 digits' => [['phone' => '01012345678', 'code' => '12345', 'purpose' => 'register'], 'code'],
    'code with letters' => [['phone' => '01012345678', 'code' => '12a456', 'purpose' => 'register'], 'code'],
    'missing purpose' => [['phone' => '01012345678', 'code' => '123456'], 'purpose'],
    'unknown purpose' => [['phone' => '01012345678', 'code' => '123456', 'purpose' => 'login'], 'purpose'],
]);

test('a request without a stateful origin cannot verify', function (): void {
    $this->json('POST', 'http://alnoor.gomal.test/api/store/auth/verify-otp', ['phone' => '01012345678', 'code' => '654321', 'purpose' => 'register'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'stateful_request_required');
});
