<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Unit;
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

    /** @var array{category: Category, carton: Unit, piece: Unit}|null */
    private ?array $catalogBasics = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['subdomain' => 'alnoor', 'name' => 'مكتبة النور']);
    }

    /**
     * One category and two units on the default tenant, created once per test.
     *
     * @return array{category: Category, carton: Unit, piece: Unit}
     */
    protected function catalogBasics(): array
    {
        return $this->catalogBasics ??= $this->inTenant(fn (): array => [
            'category' => Category::factory()->create(['name' => 'إعدادي']),
            'carton' => Unit::factory()->create(['name' => 'كرتونة']),
            'piece' => Unit::factory()->create(['name' => 'قطعة']),
        ]);
    }

    /**
     * A valid product create/update body (08-api-contract.md §6); overrides merge recursively.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function productPayload(array $overrides = []): array
    {
        $basics = $this->catalogBasics();

        $merged = array_replace_recursive([
            'name' => 'الأضواء علوم أولى إعدادي ترم أول',
            'category_id' => $basics['category']->id,
            'description' => 'كتاب العلوم',
            'custom_fields' => [],
            'sku' => [
                'code' => 'ADW-SCI-P1-T1',
                'selling_unit_id' => $basics['carton']->id,
                'base_unit_id' => $basics['piece']->id,
                'conversion_factor' => 20,
                'cover_price' => '120.00',
                'base_price' => '90.00',
                'base_price_percent' => null,
                'moq' => 5,
            ],
        ], $overrides);

        $payload = [];

        foreach ($merged as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
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
     * asTenant() for the default test tenant, or another one when given.
     *
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $callback
     * @return TReturn
     */
    protected function inTenant(callable $callback, ?Tenant $tenant = null): mixed
    {
        return $this->asTenant($tenant ?? $this->tenant, $callback);
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
