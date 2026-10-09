<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
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

test('BR-P09 tenant:create --vertical=school-books seeds the 7 school-book fields with options', function (): void {
    $tenant = createTenantFromCli('books', ['--vertical' => 'school-books']);

    $keys = ProductFieldDefinition::withoutTenantScope()->where('tenant_id', $tenant->id)->orderBy('filter_order')->pluck('key')->all();

    expect($keys)->toBe(['grade', 'subject', 'publisher', 'term', 'track', 'edition_type', 'academic_year'])
        ->and(ProductFieldOption::withoutTenantScope()->where('tenant_id', $tenant->id)->where('label', 'أولى إعدادي')->exists())->toBeTrue();

    $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'catalog.template_applied', 'actor_type' => 'system']);
});

test('BR-P09 the template is seeded only for the new tenant', function (): void {
    createTenantFromCli('books', ['--vertical' => 'school-books']);

    expect(ProductFieldDefinition::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

test('BR-P09 tenant:create without a vertical seeds no fields', function (): void {
    $tenant = createTenantFromCli('plain');

    expect(ProductFieldDefinition::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
});
