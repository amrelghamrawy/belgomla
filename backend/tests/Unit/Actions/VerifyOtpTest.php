<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\VerifyOtp;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Customers\Exceptions\OtpInvalid;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = Customer::factory()->pendingVerification()->create(['phone' => '+201012345678']);
    CustomerOtp::factory()->code('123456')->create(['phone' => '+201012345678']);
});

test('BR-CU07 D15 a registration code activates the customer and logs them in', function (): void {
    $this->freezeTime();

    $customer = app(VerifyOtp::class)->handle('+201012345678', OtpPurpose::Register, '123456', arraySession());

    expect($customer?->is($this->customer))->toBeTrue()
        ->and($customer?->status)->toBe(CustomerStatus::Active)
        ->and($customer?->phone_verified_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and(Auth::guard('customer')->id())->toBe($this->customer->id);
});

test('D2 with approval required the customer becomes pending approval', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['require_customer_approval' => true]);

    expect(app(VerifyOtp::class)->handle('+201012345678', OtpPurpose::Register, '123456', arraySession())?->status)->toBe(CustomerStatus::PendingApproval);
});

test('a wrong code changes nothing and logs nobody in', function (): void {
    expect(fn () => app(VerifyOtp::class)->handle('+201012345678', OtpPurpose::Register, '000000', arraySession()))->toThrow(OtpInvalid::class);

    expect($this->customer->refresh()->status)->toBe(CustomerStatus::PendingVerification)
        ->and(Auth::guard('customer')->check())->toBeFalse();
});

test('a customer who is not pending verification has nothing to verify', function (): void {
    $this->customer->forceFill(['status' => CustomerStatus::Active])->save();

    app(VerifyOtp::class)->handle('+201012345678', OtpPurpose::Register, '123456', arraySession());
})->throws(OtpInvalid::class);

test('a password reset code is only checked: no customer, no session, still usable', function (): void {
    $reset = CustomerOtp::factory()->passwordReset()->code('654321')->create(['phone' => '+201012345678']);

    expect(app(VerifyOtp::class)->handle('+201012345678', OtpPurpose::PasswordReset, '654321', arraySession()))->toBeNull()
        ->and($reset->refresh()->consumed_at)->toBeNull()
        ->and(Auth::guard('customer')->check())->toBeFalse();
});
