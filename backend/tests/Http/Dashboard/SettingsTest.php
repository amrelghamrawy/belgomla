<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->owner = $this->actingAsOwner();
});

test('settings are returned with the storefront shape plus merchant-only fields', function (): void {
    $this->tenantJson('GET', '/api/dashboard/settings')
        ->assertOk()
        ->assertJsonPath('data.mov.enabled', false)
        ->assertJsonPath('data.stale_pending_hours', 24);
});

test('an owner updates settings and the change is audited with old and new values', function (): void {
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

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->action)->toBe('tenant.renamed')
        ->and($logs[0]->old_values)->toBe(['name' => 'مكتبة النور'])
        ->and($logs[1]->action)->toBe('settings.updated')
        ->and($logs[1]->actor_type->value)->toBe('tenant_user')
        ->and($logs[1]->actor_id)->toBe($this->owner->id)
        ->and($logs[1]->old_values)->toMatchArray(['mov_enabled' => false, 'mov_amount' => null, 'phone' => null])
        ->and($logs[1]->new_values)->toMatchArray(['mov_enabled' => true, 'phone' => '+201000000000']);
});

test('an update that changes nothing writes no audit record', function (): void {
    $this->tenantJson('PUT', '/api/dashboard/settings', ['mov_enabled' => false, 'name' => 'مكتبة النور'])->assertOk();

    expect($this->asTenant($this->tenant, fn (): int => AuditLog::query()->count()))->toBe(0);
});

test('a nullable field sent as null is cleared', function (): void {
    $this->tenant->settings->update(['phone' => '+201000000000']);

    $this->tenantJson('PUT', '/api/dashboard/settings', ['phone' => null])->assertOk()->assertJsonPath('data.phone', null);
});

test('settings validation', function (array $body, string $field): void {
    $this->tenantJson('PUT', '/api/dashboard/settings', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'mov amount required when enabled' => [['mov_enabled' => true, 'mov_amount' => null], 'mov_amount'],
    'negative mov amount' => [['mov_enabled' => true, 'mov_amount' => -5], 'mov_amount'],
    'bad primary color' => [['primary_color' => 'red'], 'primary_color'],
    'bad secondary color' => [['secondary_color' => '#12345'], 'secondary_color'],
    'empty name' => [['name' => ''], 'name'],
    'working hours day out of range' => [['working_hours' => [['day' => 7, 'from' => '09:00', 'to' => '10:00']]], 'working_hours.0.day'],
    'working hours bad time' => [['working_hours' => [['day' => 1, 'from' => '9am', 'to' => '10:00']]], 'working_hours.0.from'],
    'stale hours too large' => [['stale_pending_hours' => 10000], 'stale_pending_hours'],
]);

test('the Arabic message for a missing minimum order amount is explicit', function (): void {
    $this->tenantJson('PUT', '/api/dashboard/settings', ['mov_enabled' => true, 'mov_amount' => null])
        ->assertJsonPath('error.details.mov_amount.0', 'يجب تحديد الحد الأدنى لقيمة الطلب عند تفعيله');
});

test('BR-T03 settings are isolated per tenant', function (): void {
    $other = $this->otherTenant();

    $this->tenantJson('PUT', '/api/dashboard/settings', ['phone' => '+201111111111'])->assertOk();

    $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $this->tenant->id, 'phone' => '+201111111111']);
    $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $other->id, 'phone' => null]);
});

test('uploading a logo stores it under the tenant folder and replaces the previous file', function (): void {
    $first = $this->tenantJson('POST', '/api/dashboard/settings/logo', ['file' => UploadedFile::fake()->image('logo.png', 200, 200)])
        ->assertOk()
        ->json('data.logo_url');

    expect($first)->toContain("tenants/{$this->tenant->id}/branding/");

    $this->tenantJson('POST', '/api/dashboard/settings/logo', ['file' => UploadedFile::fake()->image('logo2.png', 200, 200)])
        ->assertOk();

    expect(Storage::disk('public')->allFiles("tenants/{$this->tenant->id}/branding"))->toHaveCount(1);
});

test('uploading a favicon is audited', function (): void {
    $this->tenantJson('POST', '/api/dashboard/settings/favicon', ['file' => UploadedFile::fake()->image('fav.png', 32, 32)])
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'settings.favicon_changed']);
});

test('branding uploads reject non-images and unknown kinds', function (): void {
    $this->tenantJson('POST', '/api/dashboard/settings/logo', ['file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['file']]]);

    $this->tenantJson('POST', '/api/dashboard/settings/banner', ['file' => UploadedFile::fake()->image('x.png')])
        ->assertNotFound();
});
