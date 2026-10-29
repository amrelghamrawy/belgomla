<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Product;

/*
| BR-P15 (V2 Task 15, D93): POST /api/dashboard/bundles, PUT /api/dashboard/bundles/{product}, POST
| /api/dashboard/bundles/generate. A bundle is read through the products endpoints.
*/

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
    $basics = $this->catalogBasics();
    $this->arabic = $this->orderableSku(['code' => 'AR-1', 'base_price' => '92.00'], onHand: 100, name: 'عربي');
    $this->maths = $this->orderableSku(['code' => 'MA-1', 'base_price' => '113.50'], name: 'رياضيات');
    $this->piece = $basics['piece'];
    $this->category = $basics['category'];

    $this->body = fn (array $overrides = []): array => [
        'name' => 'طقم أولى إعدادي',
        'category_id' => $this->category->id,
        'code' => 'SET-1',
        'unit_id' => $this->piece->id,
        'moq' => 2,
        'components' => [
            ['sku_unit_id' => $this->inTenant(fn (): int => $this->defaultUnitId($this->arabic)), 'quantity' => 1],
            ['sku_unit_id' => $this->inTenant(fn (): int => $this->defaultUnitId($this->maths)), 'quantity' => 2],
        ],
        'pricing' => ['mode' => 'percent_off_sum', 'value' => '10'],
        ...$overrides,
    ];
});

test('BR-P15 a bundle is created and answered as a product with its bundle block', function (): void {
    $response = $this->tenantJson('POST', '/api/dashboard/bundles', ($this->body)())
        ->assertCreated()
        ->assertJsonPath('data.type', 'bundle')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.sku.code', 'SET-1')
        ->assertJsonPath('data.sku.base_price', '287.10')
        ->assertJsonPath('data.sku.moq', 2)
        ->assertJsonPath('data.bundle.pricing', ['mode' => 'percent_off_sum', 'value' => '10.00'])
        ->assertJsonPath('data.bundle.components_total', '319.00')
        ->assertJsonPath('data.bundle.availability', 5)
        ->assertJsonPath('data.bundle.components.*.sku_code', ['AR-1', 'MA-1'])
        ->assertJsonPath('data.bundle.components.1.quantity', 2)
        ->assertJsonPath('data.bundle.components.1.base_price', '113.50')
        ->assertJsonPath('data.bundle.components.0.available', 100)
        ->assertJsonPath('data.bundle.components.0.orderable', true);

    expect(array_keys($response->json('data.bundle.components.0')))->toBe(['id', 'sku_unit_id', 'sku_id', 'product_id', 'product_name', 'sku_code', 'label', 'unit', 'conversion_factor', 'quantity', 'base_price', 'available', 'orderable']);
    expectDocumentedShape($response->json('data.bundle'), 'DashboardBundle');
    expectDocumentedShape($response->json('data'), 'DashboardProduct');
});

test('BR-L creating a bundle is audited as product.created by the user', function (): void {
    $this->tenantJson('POST', '/api/dashboard/bundles', ($this->body)())->assertCreated();

    $record = AuditLog::query()->where('action', 'product.created')->sole();

    expect($record->actor_id)->toBe($this->me->id)
        ->and($record->new_values['bundle']['pricing'] ?? null)->toBe(['mode' => 'percent_off_sum', 'value' => '10.00'])
        ->and($this->inTenant(fn () => Product::query()->where('type', 'bundle')->sole()->created_by))->toBe($this->me->id);
});

test('BR-P15 the request is validated', function (array $overrides, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/bundles', ($this->body)($overrides))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'no name' => [['name' => ''], 'name'],
    'no category' => [['category_id' => null], 'category_id'],
    'a code with spaces' => [['code' => 'SET 1'], 'code'],
    'no unit' => [['unit_id' => null], 'unit_id'],
    'a moq of 0' => [['moq' => 0], 'moq'],
    'no components' => [['components' => []], 'components'],
    'a component twice' => [['components' => [['sku_unit_id' => 1, 'quantity' => 1], ['sku_unit_id' => 1, 'quantity' => 1]]], 'components.0.sku_unit_id'],
    'a quantity of 0' => [['components' => [['sku_unit_id' => 1, 'quantity' => 0]]], 'components.0.quantity'],
    'an unknown mode' => [['pricing' => ['mode' => 'free', 'value' => '1']], 'pricing.mode'],
    'a value with three decimals' => [['pricing' => ['mode' => 'fixed', 'value' => '1.005']], 'pricing.value'],
    'a fixed price of 0' => [['pricing' => ['mode' => 'fixed', 'value' => '0']], 'pricing.value'],
    'a % of 100' => [['pricing' => ['mode' => 'percent_off_sum', 'value' => '100']], 'pricing.value'],
    'a status of coming soon' => [['status' => 'coming_soon'], 'status'],
]);

test('D93 a bundle inside a bundle is refused on its component', function (): void {
    $id = $this->tenantJson('POST', '/api/dashboard/bundles', ($this->body)())->json('data.sku.units.0.id');

    $this->tenantJson('POST', '/api/dashboard/bundles', ($this->body)(['code' => 'SET-2', 'components' => [['sku_unit_id' => $id, 'quantity' => 1]]]))
        ->assertUnprocessable()
        ->assertJsonPath('error.details', ['components.0.sku_unit_id' => ['لا يمكن وضع باقة داخل باقة']]);
});

test('BR-P03 a code already used is 409 duplicate_code on the code', function (): void {
    $this->tenantJson('POST', '/api/dashboard/bundles', ($this->body)(['code' => 'AR-1']))
        ->assertConflict()
        ->assertJsonPath('error.code', 'duplicate_code')
        ->assertJsonStructure(['error' => ['details' => ['code']]]);
});

test('BR-P15 a bundle is updated in place', function (): void {
    $id = $this->tenantJson('POST', '/api/dashboard/bundles', ($this->body)())->json('data.id');

    $this->tenantJson('PUT', "/api/dashboard/bundles/{$id}", ($this->body)(['name' => 'طقم جديد', 'pricing' => ['mode' => 'fixed', 'value' => '250.00'], 'components' => [($this->body)()['components'][1]]]))
        ->assertOk()
        ->assertJsonPath('data.name', 'طقم جديد')
        ->assertJsonPath('data.sku.base_price', '250.00')
        ->assertJsonPath('data.bundle.components.*.sku_code', ['MA-1']);

    expect(AuditLog::query()->where('action', 'product.updated')->count())->toBe(1);
});

test('D93 a simple product is not found as a bundle', function (): void {
    $this->tenantJson('PUT', "/api/dashboard/bundles/{$this->arabic->product_id}", ($this->body)())
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

test('D93 a bundle is not edited, duplicated or priced through the product endpoints', function (string $method, string $path): void {
    $created = $this->tenantJson('POST', '/api/dashboard/bundles', ($this->body)());
    $uri = str_replace(['{id}', '{unit}'], [(string) $created->json('data.id'), (string) $created->json('data.sku.units.0.id')], $path);

    $this->tenantJson($method, $uri, $method === 'PUT' && str_contains($path, 'pricing') ? ['base_price' => '1.00', 'moq' => 1, 'tiers' => []] : $this->productPayload())
        ->assertConflict()
        ->assertJsonPath('error.code', 'is_bundle');
})->with([
    'product update' => ['PUT', '/api/dashboard/products/{id}'],
    'duplicate' => ['POST', '/api/dashboard/products/{id}/duplicate'],
    'unit pricing' => ['PUT', '/api/dashboard/products/{id}/units/{unit}/pricing'],
]);

test('BR-T04 a bundle of another store is not found and stays as it was', function (): void {
    $other = $this->otherTenant();
    $foreign = $this->gradeSet([$this->orderableSku(['code' => 'FR-1'], tenant: $other)->id => 1], code: 'FR-SET', tenant: $other);

    $this->tenantJson('PUT', "/api/dashboard/bundles/{$foreign->id}", ($this->body)())
        ->assertNotFound();

    expect($this->inTenant(fn () => Product::query()->findOrFail($foreign->id)->name, $other))->toBe('طقم الصف');
});

test('BR-T04 a component of another store is not found on its field', function (): void {
    $foreign = $this->orderableSku(['code' => 'FR-1'], tenant: $this->otherTenant());
    $unitId = App\Modules\Catalog\Models\SkuUnit::query()->withoutGlobalScopes()->where('sku_id', $foreign->id)->value('id');

    $this->tenantJson('POST', '/api/dashboard/bundles', ($this->body)(['components' => [['sku_unit_id' => $unitId, 'quantity' => 1]]]))
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['components.0.sku_unit_id']]]);

    expect($this->inTenant(fn () => BundleItem::query()->count()))->toBe(0);
});

test('D93 a grade set is generated from the filter as an inactive draft', function (): void {
    $this->tenantJson('POST', '/api/dashboard/bundles/generate', ['filter' => ['q' => 'رياضيات']])
        ->assertCreated()
        ->assertJsonPath('data.type', 'bundle')
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.name', 'طقم رياضيات')
        ->assertJsonPath('data.bundle.components.*.sku_code', ['MA-1'])
        ->assertJsonPath('data.bundle.pricing', ['mode' => 'percent_off_sum', 'value' => '0.00']);
});

test('BR-P12 a generation needs a filter that selects something', function (array $body): void {
    $this->tenantJson('POST', '/api/dashboard/bundles/generate', $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['filter']]]);
})->with([
    'no filter' => [[]],
    'an empty filter' => [['filter' => ['q' => '']]],
    'a filter matching nothing' => [['filter' => ['q' => 'لا يوجد']]],
]);

test('D82 a role without catalog.manage cannot write bundles', function (): void {
    $this->app['auth']->forgetGuards();
    $this->actingAs($this->makeOwner(attributes: ['email' => 'sales@alnoor.test', 'role' => 'sales']), 'tenant_user');

    $this->tenantJson('POST', '/api/dashboard/bundles', ($this->body)())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

test('a dashboard session from one tenant host cannot create a bundle on another tenant host', function (): void {
    $other = $this->otherTenant();
    $this->app['auth']->forgetGuards();
    $this->makeOwner(attributes: ['email' => 'owner2@alnoor.test', 'password' => 'secret123']);
    $this->tenantJson('POST', '/api/dashboard/auth/login', ['email' => 'owner2@alnoor.test', 'password' => 'secret123'])->assertOk();

    $this->tenantJson('POST', '/api/dashboard/bundles', ($this->body)(), $other)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('a guest cannot write bundles', function (): void {
    $this->app['auth']->forgetGuards();

    $this->tenantJson('POST', '/api/dashboard/bundles', ($this->body)())->assertUnauthorized();
});
