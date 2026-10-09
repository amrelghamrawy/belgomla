<?php

declare(strict_types=1);

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Models\Tenant;

test('tenant:create provisions the tenant, its settings and the first owner', function (): void {
    $this->artisan('tenant:create', [
        'subdomain' => 'Hadaya',
        'name' => 'مكتبة الهدايا',
        '--owner-email' => 'Owner@Hadaya.test',
        '--owner-password' => 'secret123',
        '--vertical' => 'stationery',
    ])->expectsOutputToContain('https://hadaya.gomal.test')->assertSuccessful();

    $tenant = Tenant::query()->where('subdomain', 'hadaya')->firstOrFail();

    expect($tenant->vertical)->toBe(Vertical::Stationery)
        ->and($tenant->status)->toBe(TenantStatus::Active);

    $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $tenant->id, 'currency' => 'EGP']);
    $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'email' => 'owner@hadaya.test', 'role' => 'owner']);
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
                [$this->tenant->id, 'alnoor', 'مكتبة النور', 'active', 'school-books', 2, now()->toDateString()],
                [$this->tenant->id + 1, 'empty', 'متجر آخر', 'active', 'school-books', 0, now()->toDateString()],
            ],
        )
        ->assertSuccessful();
});
