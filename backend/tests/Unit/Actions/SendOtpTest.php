<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\SendOtp;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Customers\Exceptions\OtpSendLimited;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Exceptions\MessagingRateLimited;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    Queue::fake();
    $this->freezeTime();
    app(TenantContext::class)->set($this->tenant);
    Customer::factory()->pendingVerification()->create(['phone' => '+201012345678']);
});

test('BR-CU07 a code is stored hashed for 10 minutes and sent through Messenger', function (): void {
    $channel = app(SendOtp::class)->handle('+201012345678', OtpPurpose::Register);

    $otp = CustomerOtp::query()->sole();
    expect($channel)->toBe(Channel::WhatsApp)
        ->and($otp->only(['phone', 'purpose', 'channel', 'attempts']))->toBe(['phone' => '+201012345678', 'purpose' => OtpPurpose::Register, 'channel' => 'whatsapp', 'attempts' => 0])
        ->and($otp->expires_at->toIso8601String())->toBe(now()->addMinutes(10)->toIso8601String())
        ->and(lastMessageText())->toBe('رمز التحقق في مكتبة النور: '.lastOtpCode()."\nصالح لمدة 10 دقائق. لا تشاركه مع أحد.");
});

test('BR-CU07 a new code supersedes the live ones of the same purpose only', function (): void {
    Customer::query()->update(['status' => CustomerStatus::Active]);
    $reset = CustomerOtp::factory()->passwordReset()->create();
    $older = CustomerOtp::factory()->passwordReset()->create();
    $register = CustomerOtp::factory()->create();

    app(SendOtp::class)->handle('+201012345678', OtpPurpose::PasswordReset);

    expect($reset->refresh()->consumed_at)->not->toBeNull()
        ->and($older->refresh()->consumed_at)->not->toBeNull()
        ->and($register->refresh()->consumed_at)->toBeNull();
});

test('D52 a phone that cannot use the purpose gets no code and no message, with the same answer', function (OtpPurpose $purpose, string $phone): void {
    expect(app(SendOtp::class)->handle($phone, $purpose))->toBe(Channel::WhatsApp)
        ->and(CustomerOtp::query()->count())->toBe(0)
        ->and(OutboundMessage::query()->count())->toBe(0);
})->with([
    'unknown phone' => [OtpPurpose::Register, '+201112345678'],
    'reset for a customer still pending verification' => [OtpPurpose::PasswordReset, '+201012345678'],
]);

test('BR-CU07 the sixth send within the hour is refused, per tenant', function (): void {
    $send = app(SendOtp::class);
    $send->handle('+201012345678', OtpPurpose::Register);
    $send->handle('+201012345678', OtpPurpose::Register);
    $send->handle('+201012345678', OtpPurpose::Register);
    $send->handle('+201012345678', OtpPurpose::Register);
    $send->handle('+201012345678', OtpPurpose::Register);

    expect(fn () => $send->handle('+201012345678', OtpPurpose::Register))
        ->toThrow(function (OtpSendLimited $e): void {
            expect($e->details)->toBe(['retry_after' => 3600]);
        });
});

test('BR-CU07 sms on request waits 60 seconds after the last code', function (): void {
    $send = app(SendOtp::class);
    $send->handle('+201012345678', OtpPurpose::Register);
    $this->travel(30)->seconds();

    expect(fn () => $send->handle('+201012345678', OtpPurpose::Register, Channel::Sms))
        ->toThrow(function (OtpSendLimited $e): void {
            expect($e->details)->toBe(['retry_after' => 30]);
        });
});

test('BR-CU07 sms on request with no earlier code goes straight away', function (): void {
    expect(app(SendOtp::class)->handle('+201012345678', OtpPurpose::Register, Channel::Sms))->toBe(Channel::Sms)
        ->and(CustomerOtp::query()->sole()->channel)->toBe('sms');
});

test('when Messenger refuses, no code is stored', function (): void {
    foreach (range(1, 5) as $sent) {
        RateLimiter::hit('messaging:phone:'.$this->tenant->id.':+201012345678', 3600);
    }

    expect(fn () => app(SendOtp::class)->handle('+201012345678', OtpPurpose::Register))->toThrow(MessagingRateLimited::class);

    expect(CustomerOtp::query()->count())->toBe(0);
});
