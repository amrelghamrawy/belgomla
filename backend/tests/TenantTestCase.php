<?php

namespace Tests;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Base class for feature tests that talk to the API as a tenant.
 * Requests are sent with the tenant's Host header, exactly like production.
 */
abstract class TenantTestCase extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['subdomain' => 'alnoor', 'name' => 'مكتبة النور']);
    }

    protected function hostOf(Tenant $tenant): string
    {
        return $tenant->subdomain.'.'.config('tenancy.base_domain');
    }

    /**
     * Send a JSON request to the given tenant's host, the way the Next.js SPA does:
     * absolute URL on the tenant host plus an Origin header so Sanctum treats it as stateful.
     */
    protected function tenantJson(string $method, string $uri, array $data = [], ?Tenant $tenant = null, array $headers = [])
    {
        return $this->hostJson($this->hostOf($tenant ?? $this->tenant), $method, $uri, $data, $headers);
    }

    protected function hostJson(string $host, string $method, string $uri, array $data = [], array $headers = [])
    {
        // Each real request starts with an empty tenant context; mirror that so middleware order bugs show up.
        app(TenantContext::class)->clear();

        // Symfony's Request::create() derives HTTP_HOST from the URL, so the host must be in the URL itself.
        return $this->json($method, 'http://'.$host.$uri, $data, $headers + ['Origin' => 'http://'.$host]);
    }

    /** Create a model inside a tenant's context (the trait fills tenant_id). */
    protected function asTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($tenant, $callback);
    }

    protected function makeOwner(?Tenant $tenant = null, array $attributes = []): TenantUser
    {
        $tenant ??= $this->tenant;

        return $this->asTenant($tenant, fn () => TenantUser::factory()->create($attributes));
    }

    /** Authenticate a dashboard user for subsequent requests on their tenant host. */
    protected function actingAsOwner(?TenantUser $user = null): TenantUser
    {
        $user ??= $this->makeOwner();
        $this->actingAs($user, 'tenant_user');

        return $user;
    }
}
