<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Models\Tenant;
use Tests\TenantTestCase;

class TenantResolutionTest extends TenantTestCase
{
    public function test_storefront_resolves_tenant_from_host(): void
    {
        $this->tenantJson('GET', '/api/store/tenant')
            ->assertOk()
            ->assertJsonPath('data.name', 'مكتبة النور')
            ->assertJsonPath('data.subdomain', 'alnoor')
            ->assertJsonPath('data.currency', 'EGP')
            ->assertJsonPath('data.mov.enabled', false);
    }

    public function test_unknown_subdomain_is_404(): void
    {
        $this->hostJson('nobody.gomal.test', 'GET', '/api/store/tenant')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_bare_base_domain_and_reserved_subdomains_are_404(): void
    {
        foreach (['gomal.test', 'www.gomal.test', 'api.gomal.test', 'a.b.gomal.test', 'other-domain.test'] as $host) {
            $this->hostJson($host, 'GET', '/api/store/tenant')
                ->assertNotFound();
        }
    }

    public function test_tenant_is_resolved_before_authentication(): void
    {
        // An unknown host must be a 404 even on authenticated routes, never a 401:
        // proves ResolveTenant runs ahead of the auth guard (middleware priority).
        $this->hostJson('nobody.gomal.test', 'GET', '/api/dashboard/settings')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
        $this->tenantJson('GET', '/api/dashboard/settings')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'tenant_suspended');
    }

    public function test_suspended_tenant_storefront_is_503_and_dashboard_is_403(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->tenantJson('GET', '/api/store/tenant')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'store_unavailable');

        $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'x@y.z', 'password' => 'p'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'tenant_suspended');
    }

    public function test_tenant_owned_models_cannot_be_created_without_context(): void
    {
        $this->expectException(\RuntimeException::class);

        \App\Modules\Tenancy\Models\TenantUser::factory()->create();
    }
}
