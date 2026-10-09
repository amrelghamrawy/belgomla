<?php

declare(strict_types=1);

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;

test('to array', function (): void {
    expect(array_keys($this->tenant->fresh()->toArray()))->toBe([
        'id', 'subdomain', 'name', 'status', 'vertical', 'created_at', 'updated_at',
    ]);
});

test('casts', function (): void {
    $tenant = $this->tenant->fresh();

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->vertical)->toBe(Vertical::SchoolBooks)
        ->and($tenant->created_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('status helpers', function (): void {
    expect($this->tenant->isActive())->toBeTrue()
        ->and($this->tenant->isSuspended())->toBeFalse();

    $suspended = Tenant::factory()->suspended()->create();

    expect($suspended->isActive())->toBeFalse()
        ->and($suspended->isSuspended())->toBeTrue();
});

test('host is built from the configured base domain', function (): void {
    config(['tenancy.base_domain' => 'gomalecommerce.app']);

    expect($this->tenant->host())->toBe('alnoor.gomalecommerce.app');
});

test('the factory creates the settings row', function (): void {
    expect($this->tenant->settings)->not->toBeNull()
        ->and($this->tenant->settings->currency)->toBe('EGP');
});

test('subdomain is unique across the platform', function (): void {
    Tenant::factory()->create(['subdomain' => 'alnoor']);
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);

test('users relationship is tenant-scoped through the context', function (): void {
    $this->makeOwner();
    $this->makeOwner($this->otherTenant());

    expect($this->asTenant($this->tenant, fn (): int => $this->tenant->users()->count()))->toBe(1);
});
