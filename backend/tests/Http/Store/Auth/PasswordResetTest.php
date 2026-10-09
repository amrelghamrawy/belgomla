<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Messaging\Models\OutboundMessage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->customer = $this->makeCustomer(['phone' => '+201012345678', 'password' => 'old-secret']);
});

function resetPassword(string $code, string $password = 'new-secret'): Illuminate\Testing\TestResponse
{
    return test()->tenantJson('POST', '/api/store/auth/reset-password', ['phone' => '01012345678', 'code' => $code, 'password' => $password]);
}

test('BR-CU04 forgot password sends a password_reset code to the phone', function (): void {
    $this->tenantJson('POST', '/api/store/auth/forgot-password', ['phone' => '01012345678'])
        ->assertOk()
        ->assertExactJson(['data' => ['otp_sent_via' => 'whatsapp']]);

    expect($this->inTenant(fn () => CustomerOtp::query()->sole()->purpose->value))->toBe('password_reset')
        ->and(lastOtpCode())->toMatch('/^\d{6}$/');
});

test('D52 forgot password for an unknown phone answers the same and sends nothing', function (): void {
    $this->tenantJson('POST', '/api/store/auth/forgot-password', ['phone' => '01112345678'])
        ->assertOk()
        ->assertExactJson(['data' => ['otp_sent_via' => 'whatsapp']]);

    expect($this->inTenant(fn () => OutboundMessage::query()->count()))->toBe(0);
});

test('BR-CU05 a blocked customer cannot reset their password', function (): void {
    Customer::withoutTenantScope()->update(['status' => CustomerStatus::Blocked]);

    $this->tenantJson('POST', '/api/store/auth/forgot-password', ['phone' => '01012345678'])->assertOk();

    expect($this->inTenant(fn () => CustomerOtp::query()->count()))->toBe(0);
});

test('BR-CU04 the code sets a new password, which then logs in', function (): void {
    $this->tenantJson('POST', '/api/store/auth/forgot-password', ['phone' => '01012345678'])->assertOk();

    resetPassword(lastOtpCode())->assertNoContent();

    expect(Hash::check('new-secret', (string) Customer::withoutTenantScope()->sole()->password))->toBeTrue();
    $this->tenantJson('POST', '/api/store/auth/login', ['phone' => '01012345678', 'password' => 'new-secret'])->assertOk();
});

test('BR-CU07 a reset code works once', function (): void {
    $this->tenantJson('POST', '/api/store/auth/forgot-password', ['phone' => '01012345678'])->assertOk();
    $code = lastOtpCode();
    resetPassword($code)->assertNoContent();

    resetPassword($code, 'third-secret')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'otp_invalid');
});

test('a registration code cannot reset a password', function (): void {
    $this->inTenant(fn () => CustomerOtp::factory()->code('123123')->create(['phone' => '+201012345678']));

    resetPassword('123123')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'otp_invalid');

    expect(Hash::check('old-secret', (string) Customer::withoutTenantScope()->sole()->password))->toBeTrue();
});

test('a wrong reset code keeps the old password', function (): void {
    $this->inTenant(fn () => CustomerOtp::factory()->passwordReset()->code('123123')->create(['phone' => '+201012345678']));

    resetPassword('999999')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'otp_invalid');

    expect(Hash::check('old-secret', (string) Customer::withoutTenantScope()->sole()->password))->toBeTrue();
});

test('reset-password validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/store/auth/reset-password', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'invalid phone' => [['phone' => '1', 'code' => '123456', 'password' => 'new-secret'], 'phone'],
    'missing code' => [['phone' => '01012345678', 'password' => 'new-secret'], 'code'],
    'D50 short password' => [['phone' => '01012345678', 'code' => '123456', 'password' => 'short'], 'password'],
]);

test('forgot-password validation', function (): void {
    $this->tenantJson('POST', '/api/store/auth/forgot-password', ['phone' => 'x'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['phone']]]);
});
