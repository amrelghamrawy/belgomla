<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\CheckOtp;
use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Customers\Exceptions\OtpAttemptsExceeded;
use App\Modules\Customers\Exceptions\OtpExpired;
use App\Modules\Customers\Exceptions\OtpInvalid;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->otp = CustomerOtp::factory()->code('123456')->create(['phone' => '+201012345678']);
});

test('BR-CU07 the right code is consumed and returned', function (): void {
    $otp = app(CheckOtp::class)->handle('+201012345678', OtpPurpose::Register, '123456');

    expect($otp->is($this->otp))->toBeTrue()
        ->and($this->otp->refresh()->consumed_at)->not->toBeNull();
});

test('a check without consuming leaves the code usable', function (): void {
    app(CheckOtp::class)->handle('+201012345678', OtpPurpose::Register, '123456', consume: false);

    expect($this->otp->refresh()->consumed_at)->toBeNull();
});

test('BR-CU07 a wrong code counts an attempt and says how many are left', function (int $attemptsBefore, int $left): void {
    $this->otp->forceFill(['attempts' => $attemptsBefore])->save();

    expect(fn () => app(CheckOtp::class)->handle('+201012345678', OtpPurpose::Register, '000000'))
        ->toThrow(function (OtpInvalid $e) use ($left): void {
            expect($e->details)->toBe(['attempts_left' => $left]);
        });

    expect($this->otp->refresh()->attempts)->toBe($attemptsBefore + 1);
})->with(['first try' => [0, 4], 'last try' => [4, 0]]);

test('BR-CU07 after 5 attempts the code is dead', function (): void {
    $this->otp->forceFill(['attempts' => 5])->save();

    app(CheckOtp::class)->handle('+201012345678', OtpPurpose::Register, '123456');
})->throws(OtpAttemptsExceeded::class);

test('BR-CU07 an expired code is refused', function (): void {
    $this->travel(10)->minutes();
    $this->travel(1)->seconds();

    app(CheckOtp::class)->handle('+201012345678', OtpPurpose::Register, '123456');
})->throws(OtpExpired::class);

test('only the latest live code of the phone and purpose counts', function (string $phone, OtpPurpose $purpose): void {
    app(CheckOtp::class)->handle($phone, $purpose, '123456');
})->with([
    'another phone' => ['+201112345678', OtpPurpose::Register],
    'another purpose' => ['+201012345678', OtpPurpose::PasswordReset],
])->throws(OtpInvalid::class);

test('BR-CU07 a consumed code cannot be used again', function (): void {
    app(CheckOtp::class)->handle('+201012345678', OtpPurpose::Register, '123456');

    app(CheckOtp::class)->handle('+201012345678', OtpPurpose::Register, '123456');
})->throws(OtpInvalid::class);
