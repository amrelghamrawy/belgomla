<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Platform\Models\Vertical;
use App\Modules\Tenancy\Models\Tenant;

/**
 * @param  array<string, string>  $options
 */
function createTenantFromCli(string $subdomain, array $options = []): Tenant
{
    test()->artisan('tenant:create', [
        'subdomain' => $subdomain,
        'name' => 'مكتبة',
        '--owner-email' => "owner@{$subdomain}.test",
        '--owner-password' => 'secret123',
        ...$options,
    ])->assertSuccessful();

    return Tenant::query()->where('subdomain', $subdomain)->firstOrFail();
}

test('D108 tenant:create without a vertical puts the store in libraries', function (): void {
    $tenant = createTenantFromCli('plain');

    expect($tenant->vertical_id)->toBe(Vertical::query()->where('key', 'libraries')->value('id'));
});

test('BR-P09 tenant:create --vertical=libraries copies the 11 libraries fields with their options', function (): void {
    // 7 school-books fields (books) + brand, color, size (stationery) + material (every section), BR-P09.
    $tenant = createTenantFromCli('books', ['--vertical' => 'libraries']);

    $keys = ProductFieldDefinition::withoutTenantScope()->where('tenant_id', $tenant->id)->orderBy('id')->pluck('key')->all();

    expect($keys)->toBe(['grade', 'subject', 'publisher', 'term', 'track', 'edition_type', 'academic_year', 'brand', 'color', 'size', 'material'])
        ->and(ProductFieldOption::withoutTenantScope()->where('tenant_id', $tenant->id)->where('label', 'أولى إعدادي')->exists())->toBeTrue();

    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'catalog.template_applied', 'actor_type' => 'system']);
});

test('BR-P09 the templates are copied only into the new tenant', function (): void {
    createTenantFromCli('books');

    expect(ProductFieldDefinition::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

test('D108 e tenant:create refuses an unknown or an archived vertical and lists the active keys', function (string $key): void {
    Vertical::factory()->archived()->create(['key' => 'clothes']);

    $this->artisan('tenant:create', ['subdomain' => 'fresh', 'name' => 'x', '--owner-email' => 'a@b.c', '--owner-password' => 'p', '--vertical' => $key])
        ->expectsOutputToContain('Vertical must be one of: libraries')
        ->assertFailed();

    expect(Tenant::query()->where('subdomain', 'fresh')->exists())->toBeFalse();
})->with([
    'unknown' => ['pharmacy'],
    'archived' => ['clothes'],
]);
