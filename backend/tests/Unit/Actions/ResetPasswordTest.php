<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\ResetPassword;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\OtpInvalid;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = Customer::factory()->create(['phone' => '+201012345678', 'password' => 'old-secret', 'remember_token' => 'old-token']);
    $this->otp = CustomerOtp::factory()->passwordReset()->code('123456')->create(['phone' => '+201012345678']);
});

test('BR-CU04 the code sets the new password, uses the code up and rotates the remember token', function (): void {
    app(ResetPassword::class)->handle('+201012345678', '123456', 'new-secret');

    $customer = $this->customer->refresh();
    expect(Hash::check('new-secret', (string) $customer->password))->toBeTrue()
        ->and($customer->remember_token)->not->toBe('old-token')
        ->and($this->otp->refresh()->consumed_at)->not->toBeNull();
});

test('a wrong code keeps the old password', function (): void {
    expect(fn () => app(ResetPassword::class)->handle('+201012345678', '000000', 'new-secret'))->toThrow(OtpInvalid::class);

    expect(Hash::check('old-secret', (string) $this->customer->refresh()->password))->toBeTrue();
});

test('BR-CU05 a blocked customer cannot reset even with a code', function (): void {
    $this->customer->forceFill(['status' => CustomerStatus::Blocked])->save();

    app(ResetPassword::class)->handle('+201012345678', '123456', 'new-secret');
})->throws(OtpInvalid::class);
