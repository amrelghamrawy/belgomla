<?php

declare(strict_types=1);

use App\Modules\Tenancy\Actions\SetTenantStatus;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\InvalidTenantInput;

test('suspends and re-activates a tenant by subdomain, case-insensitively', function (): void {
    $action = new SetTenantStatus;

    expect($action->handle('ALNOOR', TenantStatus::Suspended)->status)->toBe(TenantStatus::Suspended)
        ->and($this->tenant->refresh()->isSuspended())->toBeTrue();

    expect($action->handle('alnoor', TenantStatus::Active)->status)->toBe(TenantStatus::Active);
});

test('an unknown subdomain is rejected', function (): void {
    (new SetTenantStatus)->handle('ghost', TenantStatus::Active);
})->throws(InvalidTenantInput::class, 'not found');
