<?php

declare(strict_types=1);

use App\Modules\Tenancy\Actions\CreateTenant;
use App\Modules\Tenancy\Data\NewTenantData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Exceptions\InvalidTenantInput;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantCreatedHooks;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

function newTenant(string $subdomain = 'newstore', Vertical $vertical = Vertical::Hardware): NewTenantData
{
    return new NewTenantData($subdomain, 'متجر', 'Owner@X.test', 'secret', $vertical);
}

test('creates the tenant, its settings and the owner in one transaction and runs hooks in tenant context', function (): void {
    $seen = null;
    app(TenantCreatedHooks::class)->register(function (Tenant $tenant) use (&$seen): void {
        $seen = app(TenantContext::class)->id();
    });

    $tenant = app(CreateTenant::class)->handle(newTenant('NewStore'));

    expect($tenant->subdomain)->toBe('newstore')
        ->and($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->vertical)->toBe(Vertical::Hardware)
        ->and($seen)->toBe($tenant->id)
        ->and(app(TenantContext::class)->has())->toBeFalse()
        ->and($tenant->settings)->not->toBeNull();

    $owner = TenantUser::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($owner->email)->toBe('owner@x.test')
        ->and(Hash::check('secret', $owner->password))->toBeTrue();
});

test('a failing hook rolls everything back', function (): void {
    app(TenantCreatedHooks::class)->register(fn () => throw new RuntimeException('boom'));

    expect(fn () => app(CreateTenant::class)->handle(newTenant('rollback')))->toThrow(RuntimeException::class, 'boom');

    $this->assertDatabaseMissing('tenants', ['subdomain' => 'rollback']);
});

test('invalid subdomains and duplicates are rejected before touching the database', function (string $subdomain, string $code): void {
    try {
        app(CreateTenant::class)->handle(newTenant($subdomain));
        $this->fail('Expected InvalidTenantInput');
    } catch (InvalidTenantInput $e) {
        expect($e->errorCode)->toBe($code);
    }

    expect(Tenant::query()->count())->toBe(1);
})->with([
    'reserved' => ['admin', 'reserved_subdomain'],
    'leading hyphen' => ['-bad', 'invalid_subdomain'],
    'too long' => [str_repeat('a', 64), 'invalid_subdomain'],
    'duplicate' => ['alnoor', 'subdomain_taken'],
]);
