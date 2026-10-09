<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use Tests\TenantTestCase;

class DashboardAuthTest extends TenantTestCase
{
    public function test_owner_can_login_and_fetch_me(): void
    {
        $this->makeOwner(attributes: ['email' => 'owner@alnoor.test', 'password' => 'secret123']);

        $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner@alnoor.test', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('data.email', 'owner@alnoor.test')
            ->assertJsonPath('data.role', 'owner');

        $this->tenantJson('GET', '/api/dashboard/me')
            ->assertOk()
            ->assertJsonPath('data.tenant.subdomain', 'alnoor')
            ->assertJsonPath('data.user.email', 'owner@alnoor.test');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->makeOwner(attributes: ['email' => 'owner@alnoor.test', 'password' => 'secret123']);

        $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner@alnoor.test', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_user_of_another_tenant_cannot_login_here(): void
    {
        $other = Tenant::factory()->create(['subdomain' => 'other']);
        $this->makeOwner($other, ['email' => 'owner@other.test', 'password' => 'secret123']);

        // Same credentials, but sent to alnoor's host: the user must not be found.
        $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner@other.test', 'password' => 'secret123'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_session_from_one_tenant_is_rejected_on_another_host(): void
    {
        $other = Tenant::factory()->create(['subdomain' => 'other']);
        $owner = $this->makeOwner(attributes: ['email' => 'owner@alnoor.test', 'password' => 'secret123']);

        $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner@alnoor.test', 'password' => 'secret123'])
            ->assertOk();

        $this->tenantJson('GET', '/api/dashboard/me', tenant: $other)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');

        // The original session was invalidated, so even alnoor is logged out now.
        $this->tenantJson('GET', '/api/dashboard/me')->assertStatus(401);

        $this->assertDatabaseHas('tenant_users', ['id' => $owner->id, 'tenant_id' => $this->tenant->id]);
    }

    public function test_disabled_user_cannot_login(): void
    {
        $this->makeOwner(attributes: ['email' => 'owner@alnoor.test', 'password' => 'secret123', 'status' => TenantUser::STATUS_DISABLED]);

        $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner@alnoor.test', 'password' => 'secret123'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'user_disabled');
    }

    public function test_unauthenticated_dashboard_request_is_401(): void
    {
        $this->tenantJson('GET', '/api/dashboard/settings')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_logout_ends_session(): void
    {
        $this->makeOwner(attributes: ['email' => 'owner@alnoor.test', 'password' => 'secret123']);
        $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner@alnoor.test', 'password' => 'secret123'])->assertOk();

        $this->tenantJson('POST', '/api/dashboard/auth/logout')->assertNoContent();
        $this->tenantJson('GET', '/api/dashboard/me')->assertStatus(401);
    }
}
