<?php

declare(strict_types=1);

use App\Modules\Tenancy\Enums\TenantStatus;

test('the storefront resolves the tenant from the host and returns its public settings', function (): void {
    $this->tenantJson('GET', '/api/store/tenant')
        ->assertOk()
        ->assertExactJsonStructure(['data' => [
            'name', 'subdomain', 'logo_url', 'favicon_url', 'colors' => ['primary', 'secondary'],
            'phone', 'whatsapp', 'address', 'description', 'working_hours', 'currency',
            'require_login_for_prices', 'require_customer_approval', 'mov' => ['enabled', 'amount'],
        ]])
        ->assertJsonPath('data.name', 'مكتبة النور')
        ->assertJsonPath('data.subdomain', 'alnoor')
        ->assertJsonPath('data.currency', 'EGP')
        ->assertJsonPath('data.mov.enabled', false);
});

test('an unknown subdomain is a 404 with the store message', function (): void {
    $this->hostJson('nobody.gomal.test', 'GET', '/api/store/tenant')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonPath('error.message', 'المتجر غير موجود');
});

test('hosts that are not a single tenant subdomain are 404', function (string $host): void {
    $this->hostJson($host, 'GET', '/api/store/tenant')->assertNotFound();
})->with([
    'bare base domain' => 'gomal.test',
    'www' => 'www.gomal.test',
    'reserved api' => 'api.gomal.test',
    'nested subdomain' => 'a.b.gomal.test',
    'foreign domain' => 'other-domain.test',
]);

test('BR-T07 a suspended tenant storefront answers 503', function (): void {
    $this->tenant->update(['status' => TenantStatus::Suspended]);

    $this->tenantJson('GET', '/api/store/tenant')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'store_unavailable');
});

test('two tenants on different hosts see only their own settings', function (): void {
    $other = $this->otherTenant();
    $other->settings->update(['phone' => '+201111111111']);

    $this->tenantJson('GET', '/api/store/tenant')->assertJsonPath('data.phone', null);
    $this->tenantJson('GET', '/api/store/tenant', tenant: $other)->assertJsonPath('data.phone', '+201111111111');
});
