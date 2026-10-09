<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Tenancy\Enums\BrandingImageKind;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Enums\TenantUserRole;
use App\Modules\Tenancy\Enums\TenantUserStatus;
use App\Modules\Tenancy\Enums\Vertical;

test('every enum exposes labels for all cases', function (string $enum): void {
    $labels = $enum::toArray();

    expect(array_keys($labels))->toBe(array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases()));

    foreach ($labels as $label) {
        expect($label)->toBeString()->not->toBe('');
    }
})->with([
    TenantStatus::class, TenantUserStatus::class, TenantUserRole::class, Vertical::class, BrandingImageKind::class, ActorType::class,
]);

test('branding image kinds map to their settings column', function (): void {
    expect(BrandingImageKind::Logo->column())->toBe('logo_path')
        ->and(BrandingImageKind::Favicon->column())->toBe('favicon_path');
});

test('actor types know their guard', function (): void {
    expect(ActorType::TenantUser->guard())->toBe('tenant_user')
        ->and(ActorType::Customer->guard())->toBe('customer')
        ->and(ActorType::System->guard())->toBeNull();
});
