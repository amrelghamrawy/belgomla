<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Jobs\SendMessage;
use App\Modules\Messaging\Models\OutboundMessage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

function registerBody(array $overrides = []): array
{
    return [...['name' => 'محمود حسن', 'phone' => '010 1234 5678', 'password' => 'secret123'], ...$overrides];
}

test('BR-CU04 a visitor registers: pending verification, phone in E.164, otp sent over whatsapp', function (): void {
    $this->tenantJson('POST', '/api/store/auth/register', registerBody())
        ->assertOk()
        ->assertExactJson(['data' => ['otp_sent_via' => 'whatsapp']]);

    $customer = $this->inTenant(fn () => Customer::query()->sole());
    $otp = $this->inTenant(fn () => CustomerOtp::query()->sole());
    $message = $this->inTenant(fn () => OutboundMessage::query()->sole());

    expect($customer->only(['name', 'phone', 'status', 'phone_verified_at']))->toBe(['name' => 'محمود حسن', 'phone' => '+201012345678', 'status' => CustomerStatus::PendingVerification, 'phone_verified_at' => null])
        ->and(Hash::check('secret123', (string) $customer->password))->toBeTrue()
        ->and($otp->only(['phone', 'purpose', 'channel']))->toBe(['phone' => '+201012345678', 'purpose' => OtpPurpose::Register, 'channel' => 'whatsapp'])
        ->and($message->purpose)->toBe(MessagePurpose::Otp)
        ->and($message->to_phone)->toBe('+201012345678');
    Queue::assertPushed(SendMessage::class);
});

test('BR-CU07 the stored otp is a hash, the message carries the 6-digit code', function (): void {
    $this->tenantJson('POST', '/api/store/auth/register', registerBody())->assertOk();

    $code = lastOtpCode();
    $otp = $this->inTenant(fn () => CustomerOtp::query()->sole());

    expect($otp->code_hash)->not->toContain($code)
        ->and(lastMessageText())->toContain('مكتبة النور');
});

test('BR-CU08 a phone the merchant invited is sent to the invitation link', function (): void {
    $this->makeCustomer(['phone' => '+201012345678', 'status' => CustomerStatus::Invited, 'password' => null]);

    $this->tenantJson('POST', '/api/store/auth/register', registerBody())
        ->assertConflict()
        ->assertJsonPath('error.code', 'use_invitation');

    $this->assertDatabaseCount('customer_otps', 0);
});

test('BR-CU04 a phone that already has an account cannot register again', function (CustomerStatus $status): void {
    $this->makeCustomer(['phone' => '+201012345678', 'status' => $status]);

    $this->tenantJson('POST', '/api/store/auth/register', registerBody())
        ->assertConflict()
        ->assertJsonPath('error.code', 'phone_taken');

    $this->assertDatabaseCount('customers', 1);
})->with([
    'active' => [CustomerStatus::Active],
    'pending approval' => [CustomerStatus::PendingApproval],
    'blocked' => [CustomerStatus::Blocked],
]);

test('D52 a phone still waiting for its otp registers again with the new details and a new code', function (): void {
    $this->makeCustomer(['phone' => '+201012345678', 'name' => 'قديم', 'status' => CustomerStatus::PendingVerification]);

    $this->tenantJson('POST', '/api/store/auth/register', registerBody(['name' => 'جديد']))->assertOk();

    expect($this->inTenant(fn () => Customer::query()->sole()->name))->toBe('جديد')
        ->and($this->inTenant(fn () => CustomerOtp::query()->count()))->toBe(1);
});

test('BR-CU01 the same phone registers independently on another tenant', function (): void {
    $this->makeCustomer(['phone' => '+201012345678'], $this->otherTenant());

    $this->tenantJson('POST', '/api/store/auth/register', registerBody())->assertOk();

    $this->assertDatabaseCount('customers', 2);
});

test('register validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/store/auth/register', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing name' => [registerBody(['name' => null]), 'name'],
    'name too long' => [registerBody(['name' => str_repeat('م', 151)]), 'name'],
    'missing phone' => [registerBody(['phone' => null]), 'phone'],
    'landline phone' => [registerBody(['phone' => '0223456789']), 'phone'],
    'phone not a string' => [registerBody(['phone' => 1012345678]), 'phone'],
    'missing password' => [registerBody(['password' => null]), 'password'],
    'D50 password shorter than 8' => [registerBody(['password' => 'abc1234']), 'password'],
    'password too long' => [registerBody(['password' => str_repeat('a', 201)]), 'password'],
]);

test('the phone validation message is arabic', function (): void {
    $this->tenantJson('POST', '/api/store/auth/register', registerBody(['phone' => '123']))
        ->assertJsonPath('error.details.phone.0', 'رقم الهاتف يجب أن يكون رقم موبايل مصري صحيح.');
});

test('BR-CU07 the sixth code to a phone within an hour is refused with retry_after', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->tenantJson('POST', '/api/store/auth/register', registerBody())->assertOk();
    }

    $this->tenantJson('POST', '/api/store/auth/register', registerBody())
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonStructure(['error' => ['details' => ['retry_after']]]);
});

test('registration is throttled per ip', function (): void {
    foreach (range(1, 20) as $attempt) {
        $this->tenantJson('POST', '/api/store/auth/register', ['name' => '']);
    }

    $this->tenantJson('POST', '/api/store/auth/register', registerBody())
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited');
});
