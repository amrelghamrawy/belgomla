<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for tests that talk to the API as a tenant.
 * Requests are sent to the tenant's host with an Origin header, exactly like the Next.js SPA.
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
        return $tenant->subdomain.'.'.config()->string('tenancy.base_domain');
    }

    /**
     * Send a JSON request to a tenant's host (defaults to the test tenant).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function tenantJson(string $method, string $uri, array $data = [], ?Tenant $tenant = null, array $headers = []): TestResponse
    {
        return $this->hostJson($this->hostOf($tenant ?? $this->tenant), $method, $uri, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function hostJson(string $host, string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        // Each real request starts with an empty tenant context; mirror that so middleware-order bugs show up.
        app(TenantContext::class)->clear();

        // Symfony's Request::create() derives HTTP_HOST from the URL, so the host must be in the URL itself.
        return $this->json($method, 'http://'.$host.$uri, $data, $headers + ['Origin' => 'http://'.$host]);
    }

    /**
     * Run a callback inside a tenant's context (BelongsToTenant fills tenant_id).
     *
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $callback
     * @return TReturn
     */
    protected function asTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($tenant, $callback);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeOwner(?Tenant $tenant = null, array $attributes = []): TenantUser
    {
        $tenant ??= $this->tenant;

        // refresh() loads DB defaults (remember_token, timestamps) so strict-mode attribute access never throws.
        return $this->asTenant($tenant, fn (): TenantUser => TenantUser::factory()->create($attributes)->refresh());
    }

    /** Authenticate a dashboard user for subsequent requests on their tenant host. */
    protected function actingAsOwner(?TenantUser $user = null): TenantUser
    {
        $user ??= $this->makeOwner();
        $this->actingAs($user, 'tenant_user');

        return $user;
    }

    protected function otherTenant(string $subdomain = 'other'): Tenant
    {
        return Tenant::factory()->create(['subdomain' => $subdomain, 'name' => 'متجر آخر']);
    }
}
