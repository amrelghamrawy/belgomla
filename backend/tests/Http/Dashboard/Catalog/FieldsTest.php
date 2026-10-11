<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;

const FIELD_KEYS = ['id', 'key', 'label', 'type', 'kind', 'is_required', 'show_on_storefront', 'is_filterable', 'filter_order', 'sort_order', 'status', 'options'];

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
});

test('owners list their custom fields with options, archived included', function (): void {
    [$grade, $archived] = $this->inTenant(function (): array {
        $grade = ProductFieldDefinition::factory()->select()->create(['sort_order' => 1]);
        ProductFieldOption::factory()->for($grade, 'definition')->create(['label' => 'أولى إعدادي']);

        return [$grade, ProductFieldDefinition::factory()->archived()->create(['sort_order' => 2])];
    });
    $this->inTenant(fn () => ProductFieldDefinition::factory()->create(), $this->otherTenant());

    $this->tenantJson('GET', '/api/dashboard/fields')
        ->assertOk()
        ->assertExactJsonStructure(['data' => ['*' => [
            'id', 'key', 'label', 'type', 'kind', 'is_required', 'show_on_storefront', 'is_filterable', 'filter_order', 'sort_order', 'status',
            'options' => ['*' => ['id', 'label', 'sort_order', 'status']],
        ]]])
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $grade->id)
        ->assertJsonPath('data.0.kind', null)
        ->assertJsonPath('data.0.options.0.label', 'أولى إعدادي')
        ->assertJsonPath('data.1.id', $archived->id)
        ->assertJsonPath('data.1.status', 'archived');
});

test('BR-P08 an owner creates a filterable select field with ordered options, audited', function (): void {
    $response = $this->tenantJson('POST', '/api/dashboard/fields', [
        'key' => 'publisher', 'label' => 'الشركة', 'type' => 'select', 'is_filterable' => true, 'filter_order' => 3,
        'options' => ['الأضواء', 'المعلم'],
    ])
        ->assertCreated()
        ->assertExactJsonStructure(['data' => FIELD_KEYS])
        ->assertJsonPath('data.key', 'publisher')
        ->assertJsonPath('data.type', 'select')
        ->assertJsonPath('data.is_required', false)
        ->assertJsonPath('data.kind', null)
        ->assertJsonPath('data.show_on_storefront', true)
        ->assertJsonPath('data.is_filterable', true)
        ->assertJsonPath('data.filter_order', 3)
        ->assertJsonPath('data.options.0.label', 'الأضواء')
        ->assertJsonPath('data.options.0.sort_order', 1)
        ->assertJsonPath('data.options.1.label', 'المعلم');

    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $this->tenant->id, 'action' => 'field.created', 'entity_id' => $response->json('data.id'), 'actor_id' => $this->me->id,
    ]);
});

test('D69 an owner binds a new field to a section', function (): void {
    $this->tenantJson('POST', '/api/dashboard/fields', ['key' => 'age', 'label' => 'العمر', 'type' => 'text', 'kind' => 'toys'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'toys');

    $this->assertDatabaseHas('product_field_definitions', ['tenant_id' => $this->tenant->id, 'key' => 'age', 'kind' => 'toys']);
});

test('D69 an owner re-binds a field to a section and back to every section, audited', function (): void {
    $field = $this->inTenant(fn () => ProductFieldDefinition::factory()->create(['key' => 'brand']));

    $this->tenantJson('PUT', "/api/dashboard/fields/{$field->id}", ['label' => 'x', 'type' => 'text', 'kind' => 'stationery'])
        ->assertOk()
        ->assertJsonPath('data.kind', 'stationery');
    $this->assertDatabaseHas('audit_logs', ['action' => 'field.updated', 'entity_id' => $field->id, 'new_values->kind' => 'stationery']);

    $this->tenantJson('PUT', "/api/dashboard/fields/{$field->id}", ['label' => 'x', 'type' => 'text', 'kind' => null])
        ->assertOk()
        ->assertJsonPath('data.kind', null);
});

test('D69 a field update without kind keeps the binding', function (): void {
    $field = $this->inTenant(fn () => ProductFieldDefinition::factory()->forKind(CategoryKind::Books)->create(['key' => 'publisher']));

    $this->tenantJson('PUT', "/api/dashboard/fields/{$field->id}", ['label' => 'الناشر', 'type' => 'text'])
        ->assertOk()
        ->assertJsonPath('data.kind', 'books');
});

test('field creation validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/dashboard/fields', $body + ['key' => 'brand', 'label' => 'الماركة', 'type' => 'text'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing key' => [['key' => ''], 'key'],
    'key with capitals' => [['key' => 'Brand'], 'key'],
    'key with spaces' => [['key' => 'my brand'], 'key'],
    'key starting with a digit' => [['key' => '1brand'], 'key'],
    'key too long' => [['key' => str_repeat('a', 51)], 'key'],
    'missing label' => [['label' => ''], 'label'],
    'label too long' => [['label' => str_repeat('a', 101)], 'label'],
    'unknown type' => [['type' => 'date'], 'type'],
    'unknown kind' => [['kind' => 'furniture'], 'kind'],
    'is_required not boolean' => [['is_required' => 'maybe'], 'is_required'],
    'show_on_storefront not boolean' => [['show_on_storefront' => 'maybe'], 'show_on_storefront'],
    'is_filterable not boolean' => [['is_filterable' => 'maybe'], 'is_filterable'],
    'filter order negative' => [['filter_order' => -1], 'filter_order'],
    'sort order not integer' => [['sort_order' => 'x'], 'sort_order'],
    'options not a list' => [['type' => 'select', 'options' => 'a,b'], 'options'],
    'empty option label' => [['type' => 'select', 'options' => ['']], 'options.0'],
    'duplicate option labels' => [['type' => 'select', 'options' => ['a', 'a']], 'options.0'],
]);

test('BR-P08 a field key is unique per tenant', function (): void {
    $this->inTenant(fn () => ProductFieldDefinition::factory()->create(['key' => 'brand']));

    $this->tenantJson('POST', '/api/dashboard/fields', ['key' => 'brand', 'label' => 'x', 'type' => 'text'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'duplicate_key');
});

test('BR-P08 the same key is allowed when another tenant has it', function (): void {
    $this->inTenant(fn () => ProductFieldDefinition::factory()->create(['key' => 'brand']), $this->otherTenant());

    $this->tenantJson('POST', '/api/dashboard/fields', ['key' => 'brand', 'label' => 'x', 'type' => 'text'])->assertCreated();
});

test('BR-P08 text and number fields cannot be filterable', function (string $type): void {
    $this->tenantJson('POST', '/api/dashboard/fields', ['key' => 'k', 'label' => 'x', 'type' => $type, 'is_filterable' => true])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['is_filterable']]]);

    $this->assertDatabaseMissing('product_field_definitions', ['key' => 'k']);
})->with(['text', 'number']);

test('BR-P08 options are only accepted for select fields', function (): void {
    $this->tenantJson('POST', '/api/dashboard/fields', ['key' => 'k', 'label' => 'x', 'type' => 'boolean', 'options' => ['a']])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['options']]]);
});

test('an owner updates label, flags and ordering, audited', function (): void {
    $field = $this->inTenant(fn () => ProductFieldDefinition::factory()->select()->create(['label' => 'قديم']));

    $this->tenantJson('PUT', "/api/dashboard/fields/{$field->id}", [
        'label' => 'الشركة', 'type' => 'select', 'is_required' => true, 'is_filterable' => true, 'show_on_storefront' => false, 'filter_order' => 2, 'sort_order' => 4,
    ])
        ->assertOk()
        ->assertJsonPath('data.label', 'الشركة')
        ->assertJsonPath('data.is_required', true)
        ->assertJsonPath('data.is_filterable', true)
        ->assertJsonPath('data.show_on_storefront', false)
        ->assertJsonPath('data.filter_order', 2)
        ->assertJsonPath('data.sort_order', 4);

    $this->assertDatabaseHas('audit_logs', ['action' => 'field.updated', 'entity_id' => $field->id]);
});

test('the key and the options cannot be changed through the field update', function (string $prohibited, mixed $value): void {
    $field = $this->inTenant(fn () => ProductFieldDefinition::factory()->create(['key' => 'brand']));

    $this->tenantJson('PUT', "/api/dashboard/fields/{$field->id}", ['label' => 'x', 'type' => 'text', $prohibited => $value])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$prohibited]]]);
})->with([
    'key' => ['key', 'other'],
    'options' => ['options', ['a']],
]);

test('BR-P08 the type of a field cannot change once a product holds a value for it', function (): void {
    $field = $this->inTenant(function (): ProductFieldDefinition {
        $field = ProductFieldDefinition::factory()->create(['key' => 'brand']);
        Product::factory()->create(['custom_fields' => ['brand' => 'Bic']]);

        return $field;
    });

    $this->tenantJson('PUT', "/api/dashboard/fields/{$field->id}", ['label' => 'x', 'type' => 'number'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'field_type_locked');

    $this->assertDatabaseHas('product_field_definitions', ['id' => $field->id, 'type' => 'text']);
});

test('BR-P08 the type of a field without values can change', function (): void {
    $field = $this->inTenant(fn () => ProductFieldDefinition::factory()->create(['key' => 'brand']));

    $this->tenantJson('PUT', "/api/dashboard/fields/{$field->id}", ['label' => 'x', 'type' => 'select'])
        ->assertOk()
        ->assertJsonPath('data.type', 'select');
});

test('BR-P08 archiving a field keeps the product values, audited', function (): void {
    [$field, $product] = $this->inTenant(fn (): array => [
        ProductFieldDefinition::factory()->create(['key' => 'brand']),
        Product::factory()->create(['custom_fields' => ['brand' => 'Bic']]),
    ]);

    $this->tenantJson('POST', "/api/dashboard/fields/{$field->id}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'archived');

    expect(Product::withoutTenantScope()->findOrFail($product->id)->custom_fields)->toBe(['brand' => 'Bic']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'field.archived', 'entity_id' => $field->id]);
});

test('field update validation', function (array $body, string $field): void {
    $definition = $this->inTenant(fn () => ProductFieldDefinition::factory()->create());

    $this->tenantJson('PUT', "/api/dashboard/fields/{$definition->id}", $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing label' => [['type' => 'text'], 'label'],
    'missing type' => [['label' => 'x'], 'type'],
    'unknown type' => [['label' => 'x', 'type' => 'date'], 'type'],
    'unknown kind' => [['label' => 'x', 'type' => 'text', 'kind' => 'furniture'], 'kind'],
    'filterable text' => [['label' => 'x', 'type' => 'text', 'is_filterable' => true], 'is_filterable'],
]);

test('BR-T04 a field of another tenant is 404 and untouched', function (string $method, string $suffix): void {
    $foreign = $this->inTenant(fn () => ProductFieldDefinition::factory()->create(['label' => 'original']), $this->otherTenant());

    $this->tenantJson($method, "/api/dashboard/fields/{$foreign->id}{$suffix}", ['label' => 'hacked', 'type' => 'text'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('product_field_definitions', ['id' => $foreign->id, 'label' => 'original', 'status' => 'active']);
})->with([
    'update' => ['PUT', ''],
    'archive' => ['POST', '/archive'],
]);
