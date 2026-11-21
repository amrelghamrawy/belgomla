<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Vertical;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;

test('tenant:create provisions the tenant, its settings and the first owner', function (): void {
    $this->artisan('tenant:create', [
        'subdomain' => 'Hadaya',
        'name' => 'مكتبة الهدايا',
        '--owner-email' => 'Owner@Hadaya.test',
        '--owner-password' => 'secret123',
        '--vertical' => 'libraries',
    ])->expectsOutputToContain('https://hadaya.gomal.test')->assertSuccessful();

    $tenant = Tenant::query()->where('subdomain', 'hadaya')->firstOrFail();

    expect($tenant->vertical_id)->toBe(Vertical::query()->where('key', 'libraries')->value('id'))
        ->and($tenant->status)->toBe(TenantStatus::Active);

    $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $tenant->id, 'currency' => 'EGP']);
    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'email' => 'owner@hadaya.test', 'role' => 'owner', 'phone' => null]);
});

test('D112 tenant:create stores the owner phone in E.164 for the password reset code', function (): void {
    $this->artisan('tenant:create', [
        'subdomain' => 'hadaya',
        'name' => 'مكتبة الهدايا',
        '--owner-email' => 'owner@hadaya.test',
        '--owner-password' => 'secret123',
        '--owner-phone' => '0101 234 5678',
    ])->assertSuccessful();

    $this->assertDatabaseHas('tenant_users', ['email' => 'owner@hadaya.test', 'phone' => '+201012345678']);
});

test('tenant:create rejects invalid input', function (array $args): void {
    $this->artisan('tenant:create', $args + ['name' => 'x', '--owner-email' => 'a@b.c', '--owner-password' => 'p'])
        ->assertFailed();
})->with([
    'reserved subdomain' => [['subdomain' => 'api']],
    'bad characters' => [['subdomain' => 'my_store!']],
    'too long' => [['subdomain' => str_repeat('a', 64)]],
    'existing subdomain' => [['subdomain' => 'alnoor']],
    'unknown vertical' => [['subdomain' => 'fresh', '--vertical' => 'pharmacy']],
    'D112 owner phone that is not an Egyptian mobile' => [['subdomain' => 'fresh', '--owner-phone' => '0223456789']],
]);

test('tenant:suspend and tenant:activate flip the status', function (): void {
    $this->artisan('tenant:suspend', ['subdomain' => 'alnoor'])
        ->expectsOutputToContain('now suspended')
        ->assertSuccessful();

    expect($this->tenant->refresh()->status)->toBe(TenantStatus::Suspended);

    $this->artisan('tenant:activate', ['subdomain' => 'ALNOOR'])->assertSuccessful();

    expect($this->tenant->refresh()->status)->toBe(TenantStatus::Active);
});

test('status commands fail for an unknown tenant', function (): void {
    $this->artisan('tenant:suspend', ['subdomain' => 'ghost'])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});

test('tenant:list shows every tenant with its user count', function (): void {
    $this->makeOwner();
    $this->makeOwner();
    $this->otherTenant('empty');

    $this->artisan('tenant:list')
        ->expectsTable(
            ['ID', 'Subdomain', 'Name', 'Status', 'Vertical', 'Users', 'Created'],
            [
                [$this->tenant->id, 'alnoor', 'مكتبة النور', 'active', 'libraries', 2, now()->toDateString()],
                [$this->tenant->id + 1, 'empty', 'متجر آخر', 'active', 'libraries', 0, now()->toDateString()],
            ],
        )
        ->assertSuccessful();
});
