<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Models\TenantUserOtp;
use App\Modules\Tenancy\Services\OtpCodes;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('D112 to array never exposes the code hash', function (): void {
    $otp = TenantUserOtp::factory()->create()->fresh();

    expect(array_keys($otp?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'tenant_user_id', 'expires_at', 'attempts', 'consumed_at', 'created_at', 'updated_at',
    ]);
});

test('D112 casts: integer attempts, dates, and the user it belongs to', function (): void {
    $otp = TenantUserOtp::factory()->consumed()->create()->fresh();

    expect($otp?->attempts)->toBe(0)
        ->and($otp?->expires_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($otp?->consumed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($otp?->user)->toBeInstanceOf(TenantUser::class)
        ->and($otp?->user?->phone)->toBe('+201012345678');
});

test('D112 factory states: a chosen code, expired', function (): void {
    $otp = TenantUserOtp::factory()->code('999000')->expired()->create();

    expect(app(OtpCodes::class)->matches('999000', $otp->code_hash))->toBeTrue()
        ->and($otp->expires_at->isPast())->toBeTrue();
});
