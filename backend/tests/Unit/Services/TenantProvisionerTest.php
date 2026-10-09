<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\Hash;

test('create builds tenant, settings and owner in one transaction and runs the hooks in tenant context', function (): void {
    $provisioner = app(TenantProvisioner::class);
    $seen = null;
    $provisioner->onCreated(function (Tenant $tenant) use (&$seen): void {
        $seen = app(TenantContext::class)->id();
    });

    $tenant = $provisioner->create('NewStore', 'متجر', 'Owner@X.test', 'secret', 'hardware');

    expect($tenant->subdomain)->toBe('newstore')
        ->and($tenant->vertical)->toBe('hardware')
        ->and($seen)->toBe($tenant->id)
        ->and(app(TenantContext::class)->has())->toBeFalse();

    $owner = TenantUser::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($owner->email)->toBe('owner@x.test')
        ->and(Hash::check('secret', $owner->password))->toBeTrue();
});

test('a failing hook rolls everything back', function (): void {
    $provisioner = app(TenantProvisioner::class);
    $provisioner->onCreated(fn () => throw new RuntimeException('boom'));

    expect(fn () => $provisioner->create('rollback', 'x', 'a@b.c', 'p'))->toThrow(RuntimeException::class, 'boom');

    $this->assertDatabaseMissing('tenants', ['subdomain' => 'rollback']);
});

test('invalid subdomains, verticals and duplicates are rejected before touching the database', function (string $subdomain, string $vertical, string $message): void {
    expect(fn () => app(TenantProvisioner::class)->create($subdomain, 'x', 'a@b.c', 'p', $vertical))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'reserved' => ['admin', 'none', 'reserved'],
    'leading hyphen' => ['-bad', 'none', 'Subdomain must be'],
    'duplicate' => ['alnoor', 'none', 'already exists'],
    'vertical' => ['fresh', 'pharmacy', 'Vertical must be'],
]);

test('setStatus updates a tenant and rejects unknown subdomains', function (): void {
    $provisioner = app(TenantProvisioner::class);

    expect($provisioner->setStatus('alnoor', Tenant::STATUS_SUSPENDED)->status)->toBe('suspended');

    expect(fn () => $provisioner->setStatus('ghost', Tenant::STATUS_ACTIVE))->toThrow(InvalidArgumentException::class, 'not found');
});
