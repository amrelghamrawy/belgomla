<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

test('to array hides the password and remember token', function (): void {
    $user = $this->makeOwner()->fresh();

    expect(array_keys($user->toArray()))->toBe([
        'id', 'tenant_id', 'name', 'email', 'role', 'status', 'last_login_at', 'created_at', 'updated_at',
    ]);
});

test('passwords are hashed by the cast', function (): void {
    $user = $this->makeOwner(attributes: ['password' => 'plain-text']);

    expect($user->password)->not->toBe('plain-text')
        ->and(Hash::check('plain-text', $user->password))->toBeTrue();
});

test('last_login_at is a datetime', function (): void {
    $user = $this->makeOwner(attributes: ['last_login_at' => '2026-10-09 10:00:00']);

    expect($user->last_login_at)->toBeInstanceOf(Illuminate\Support\Carbon::class);
});

test('BR-T03 creating a tenant user without a tenant in context throws', function (): void {
    TenantUser::factory()->create();
})->throws(RuntimeException::class, 'without a tenant in context');

test('BR-T03 creating a tenant user for a different tenant than the context throws', function (): void {
    $other = $this->otherTenant();

    $this->asTenant($this->tenant, fn () => TenantUser::factory()->create(['tenant_id' => $other->id]));
})->throws(RuntimeException::class, 'Refusing to create');

test('BR-T03 queries without a tenant in context return nothing', function (): void {
    $this->makeOwner();
    app(TenantContext::class)->clear();

    expect(TenantUser::query()->count())->toBe(0)
        ->and(TenantUser::withoutTenantScope()->count())->toBe(1);
});

test('BR-T03 queries in context only see the current tenant', function (): void {
    $mine = $this->makeOwner();
    $this->makeOwner($this->otherTenant());

    $ids = $this->asTenant($this->tenant, fn () => TenantUser::query()->pluck('id')->all());

    expect($ids)->toBe([$mine->id]);
});

test('the api array exposes only safe fields', function (): void {
    $user = $this->makeOwner();

    expect(array_keys($user->toApiArray()))->toBe(['id', 'name', 'email', 'role', 'status', 'last_login_at']);
});
