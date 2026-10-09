<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

class DashboardSettingsTest extends TenantTestCase
{
    public function test_owner_can_read_and_update_settings_with_audit(): void
    {
        $owner = $this->actingAsOwner();

        $this->tenantJson('GET', '/api/dashboard/settings')
            ->assertOk()
            ->assertJsonPath('data.mov.enabled', false);

        $this->tenantJson('PUT', '/api/dashboard/settings', [
            'name' => 'مكتبة النور الجديدة',
            'phone' => '+201000000000',
            'mov_enabled' => true,
            'mov_amount' => 1000,
            'require_login_for_prices' => true,
            'working_hours' => [['day' => 6, 'from' => '09:00', 'to' => '21:00']],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'مكتبة النور الجديدة')
            ->assertJsonPath('data.mov.enabled', true)
            ->assertJsonPath('data.mov.amount', '1000.00')
            ->assertJsonPath('data.require_login_for_prices', true)
            ->assertJsonPath('data.working_hours.0.day', 6);

        $this->assertDatabaseHas('tenants', ['id' => $this->tenant->id, 'name' => 'مكتبة النور الجديدة']);

        $logs = $this->asTenant($this->tenant, fn () => AuditLog::query()->orderBy('id')->get());
        $this->assertCount(2, $logs);
        $this->assertSame('tenant.renamed', $logs[0]->action);
        $this->assertSame('settings.updated', $logs[1]->action);
        $this->assertSame('tenant_user', $logs[1]->actor_type);
        $this->assertSame($owner->id, $logs[1]->actor_id);
        $this->assertEquals(['mov_enabled' => false, 'mov_amount' => null, 'phone' => null, 'require_login_for_prices' => false, 'working_hours' => null],
            array_intersect_key($logs[1]->old_values, array_flip(['mov_enabled', 'mov_amount', 'phone', 'require_login_for_prices', 'working_hours'])));
    }

    public function test_mov_amount_is_required_when_enabled(): void
    {
        $this->actingAsOwner();

        $this->tenantJson('PUT', '/api/dashboard/settings', ['mov_enabled' => true, 'mov_amount' => null])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['mov_amount']]]);
    }

    public function test_settings_are_isolated_per_tenant(): void
    {
        $other = Tenant::factory()->create(['subdomain' => 'other']);
        $this->actingAsOwner();

        $this->tenantJson('PUT', '/api/dashboard/settings', ['phone' => '+201111111111'])->assertOk();

        $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $this->tenant->id, 'phone' => '+201111111111']);
        $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $other->id, 'phone' => null]);
    }

    public function test_logo_upload_replaces_previous_file(): void
    {
        Storage::fake('public');
        $this->actingAsOwner();

        $first = $this->tenantJson('POST', '/api/dashboard/settings/logo', ['file' => UploadedFile::fake()->image('logo.png', 200, 200)])
            ->assertOk()
            ->json('data.logo_url');

        $this->assertNotNull($first);

        $this->tenantJson('POST', '/api/dashboard/settings/logo', ['file' => UploadedFile::fake()->image('logo2.png', 200, 200)])
            ->assertOk();

        $paths = Storage::disk('public')->allFiles("tenants/{$this->tenant->id}/branding");
        $this->assertCount(1, $paths);
    }

    public function test_users_management(): void
    {
        $me = $this->actingAsOwner();

        $created = $this->tenantJson('POST', '/api/dashboard/users', ['name' => 'أحمد', 'email' => 'Ahmed@Alnoor.test', 'password' => 'secret123'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'ahmed@alnoor.test')
            ->json('data.id');

        $this->tenantJson('POST', '/api/dashboard/users', ['name' => 'x', 'email' => 'ahmed@alnoor.test', 'password' => 'secret123'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'email_taken');

        $this->tenantJson('PATCH', "/api/dashboard/users/{$me->id}", ['status' => 'disabled'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'cannot_disable_self');

        $this->tenantJson('PATCH', "/api/dashboard/users/{$created}", ['status' => 'disabled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        $this->tenantJson('GET', '/api/dashboard/users')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_user_id_of_another_tenant_is_404(): void
    {
        $other = Tenant::factory()->create(['subdomain' => 'other']);
        $foreign = $this->makeOwner($other);
        $this->actingAsOwner();

        $this->tenantJson('PATCH', "/api/dashboard/users/{$foreign->id}", ['name' => 'hack'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }
}
