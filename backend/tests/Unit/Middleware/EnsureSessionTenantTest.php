<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Middleware\EnsureSessionTenant;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/api/_who', fn (): array => ['id' => auth('tenant_user')->id()])
        // 'api' brings Sanctum's stateful middleware, so the session exists exactly as on real dashboard routes.
        ->middleware(['api', ResolveTenant::class, 'auth:tenant_user', EnsureSessionTenant::class.':tenant_user']);
});

test('a session bound to the current tenant passes', function (): void {
    $owner = $this->actingAsOwner();

    $this->withSession([EnsureSessionTenant::SESSION_KEY => $this->tenant->id])
        ->tenantJson('GET', '/api/_who')->assertOk()->assertJsonPath('id', $owner->id);
});

test('a session bound to another tenant is logged out with 401', function (): void {
    $this->actingAsOwner();

    $this->withSession([EnsureSessionTenant::SESSION_KEY => $this->tenant->id + 99])
        ->tenantJson('GET', '/api/_who')->assertUnauthorized();

    expect(auth('tenant_user')->check())->toBeFalse();
});

test('a user whose own tenant differs from the host is rejected even without a session marker', function (): void {
    $foreign = $this->makeOwner($this->otherTenant());
    $this->actingAs($foreign, 'tenant_user');

    $this->tenantJson('GET', '/api/_who')->assertUnauthorized();
});
