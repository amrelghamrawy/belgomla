<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

test('to array', function (): void {
    expect(array_keys($this->tenant->settings->fresh()->toArray()))->toBe([
        'tenant_id', 'logo_path', 'favicon_path', 'primary_color', 'secondary_color', 'phone', 'whatsapp',
        'address', 'description', 'working_hours', 'currency', 'mov_enabled', 'mov_amount',
        'require_login_for_prices', 'require_customer_approval', 'stale_pending_hours', 'created_at', 'updated_at',
    ]);
});

test('casts', function (): void {
    $settings = $this->tenant->settings;
    $settings->update(['mov_enabled' => '1', 'mov_amount' => 1000, 'working_hours' => [['day' => 1, 'from' => '09:00', 'to' => '17:00']], 'stale_pending_hours' => '48']);
    $settings->refresh();

    expect($settings->mov_enabled)->toBeTrue()
        ->and($settings->mov_amount)->toBe('1000.00')
        ->and($settings->working_hours)->toEqual([['day' => 1, 'from' => '09:00', 'to' => '17:00']])
        ->and($settings->stale_pending_hours)->toBe(48)
        ->and($settings->require_login_for_prices)->toBeFalse();
});

test('image urls are null until a file is stored', function (): void {
    $settings = $this->tenant->settings;

    expect($settings->logoUrl())->toBeNull()
        ->and($settings->faviconUrl())->toBeNull();

    $settings->forceFill(['logo_path' => 'tenants/1/branding/logo.png'])->save();

    expect($settings->logoUrl())->toBe(Storage::disk('public')->url('tenants/1/branding/logo.png'));
});

test('the public array never exposes storage paths or internal flags', function (): void {
    $public = $this->tenant->settings->toPublicArray($this->tenant);

    expect($public)->not->toHaveKeys(['logo_path', 'favicon_path', 'stale_pending_hours', 'tenant_id'])
        ->and($public['mov'])->toBe(['enabled' => false, 'amount' => null]);
});
