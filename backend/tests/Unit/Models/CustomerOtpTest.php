<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Customers\Services\OtpCodes;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array never exposes the code hash', function (): void {
    $otp = CustomerOtp::factory()->create()->fresh();

    expect(array_keys($otp?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'phone', 'purpose', 'channel', 'expires_at', 'attempts', 'consumed_at', 'created_at', 'updated_at',
    ]);
});

test('casts: purpose enum, integer attempts, dates', function (): void {
    $otp = CustomerOtp::factory()->passwordReset()->consumed()->create()->fresh();

    expect($otp?->purpose)->toBe(OtpPurpose::PasswordReset)
        ->and($otp?->attempts)->toBe(0)
        ->and($otp?->expires_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($otp?->consumed_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('factory states: a chosen code, expired', function (): void {
    $otp = CustomerOtp::factory()->code('999000')->expired()->create();

    expect(app(OtpCodes::class)->matches('999000', $otp->code_hash))->toBeTrue()
        ->and($otp->expires_at->isPast())->toBeTrue();
});
