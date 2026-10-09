<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_suspend_activate_list(): void
    {
        $this->artisan('tenant:create', [
            'subdomain' => 'Hadaya',
            'name' => 'مكتبة الهدايا',
            '--owner-email' => 'Owner@Hadaya.test',
            '--owner-password' => 'secret123',
            '--vertical' => 'stationery',
        ])->assertSuccessful();

        $tenant = Tenant::query()->where('subdomain', 'hadaya')->firstOrFail();
        $this->assertSame('stationery', $tenant->vertical);
        $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $tenant->id, 'currency' => 'EGP']);
        $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'email' => 'owner@hadaya.test', 'role' => 'owner']);

        $this->artisan('tenant:suspend', ['subdomain' => 'hadaya'])->assertSuccessful();
        $this->assertSame(Tenant::STATUS_SUSPENDED, $tenant->refresh()->status);

        $this->artisan('tenant:activate', ['subdomain' => 'hadaya'])->assertSuccessful();
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->refresh()->status);

        $this->artisan('tenant:list')->expectsOutputToContain('hadaya')->assertSuccessful();
    }

    public function test_reserved_and_duplicate_subdomains_fail(): void
    {
        $this->artisan('tenant:create', ['subdomain' => 'api', 'name' => 'x', '--owner-email' => 'a@b.c', '--owner-password' => 'p'])
            ->assertFailed();

        Tenant::factory()->create(['subdomain' => 'alnoor']);

        $this->artisan('tenant:create', ['subdomain' => 'alnoor', 'name' => 'x', '--owner-email' => 'a@b.c', '--owner-password' => 'p'])
            ->assertFailed();
    }
}
