<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
    $this->field = $this->inTenant(fn () => ProductFieldDefinition::factory()->select()->create(['key' => 'publisher']));
});

test('BR-P08 an option is added last when no sort order is given, audited', function (): void {
    $this->inTenant(fn () => ProductFieldOption::factory()->for($this->field, 'definition')->create(['sort_order' => 4]));

    $id = $this->tenantJson('POST', "/api/dashboard/fields/{$this->field->id}/options", ['label' => 'الأضواء'])
        ->assertCreated()
        ->assertExactJsonStructure(['data' => ['id', 'label', 'sort_order', 'status']])
        ->assertJsonPath('data.label', 'الأضواء')
        ->assertJsonPath('data.sort_order', 5)
        ->assertJsonPath('data.status', 'active')
        ->json('data.id');

    $this->assertDatabaseHas('product_field_options', ['id' => $id, 'definition_id' => $this->field->id, 'tenant_id' => $this->tenant->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'field_option.created', 'entity_id' => $id, 'actor_id' => $this->me->id]);
});

test('an option can be added at a given sort order', function (): void {
    $this->tenantJson('POST', "/api/dashboard/fields/{$this->field->id}/options", ['label' => 'المعلم', 'sort_order' => 0])
        ->assertCreated()
        ->assertJsonPath('data.sort_order', 0);
});

test('option validation', function (array $body, string $field): void {
    $this->tenantJson('POST', "/api/dashboard/fields/{$this->field->id}/options", $body)
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing label' => [[], 'label'],
    'label too long' => [['label' => str_repeat('a', 101)], 'label'],
    'negative sort order' => [['label' => 'x', 'sort_order' => -1], 'sort_order'],
]);

test('BR-P08 an option label is unique within its field', function (): void {
    $this->inTenant(fn () => ProductFieldOption::factory()->for($this->field, 'definition')->create(['label' => 'الأضواء']));

    $this->tenantJson('POST', "/api/dashboard/fields/{$this->field->id}/options", ['label' => 'الأضواء'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'duplicate_label');
});

test('BR-P08 options can only be added to select fields', function (): void {
    $text = $this->inTenant(fn () => ProductFieldDefinition::factory()->create());

    $this->tenantJson('POST', "/api/dashboard/fields/{$text->id}/options", ['label' => 'x'])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['field']]]);
});

test('an option is renamed and reordered, audited', function (): void {
    $option = $this->inTenant(fn () => ProductFieldOption::factory()->for($this->field, 'definition')->create(['label' => 'الاضواء', 'sort_order' => 1]));

    $this->tenantJson('PUT', "/api/dashboard/fields/{$this->field->id}/options/{$option->id}", ['label' => 'الأضواء', 'sort_order' => 2])
        ->assertOk()
        ->assertJsonPath('data.label', 'الأضواء')
        ->assertJsonPath('data.sort_order', 2);

    $this->assertDatabaseHas('audit_logs', ['action' => 'field_option.updated', 'entity_id' => $option->id]);
});

test('an option update keeps fields that are not sent', function (): void {
    $option = $this->inTenant(fn () => ProductFieldOption::factory()->for($this->field, 'definition')->create(['label' => 'الأضواء', 'sort_order' => 3]));

    $this->tenantJson('PUT', "/api/dashboard/fields/{$this->field->id}/options/{$option->id}", ['sort_order' => 7])
        ->assertOk()
        ->assertJsonPath('data.label', 'الأضواء')
        ->assertJsonPath('data.sort_order', 7);
});

test('renaming an option to a label already used in the field conflicts', function (): void {
    $option = $this->inTenant(function (): ProductFieldOption {
        ProductFieldOption::factory()->for($this->field, 'definition')->create(['label' => 'المعلم']);

        return ProductFieldOption::factory()->for($this->field, 'definition')->create(['label' => 'الأضواء']);
    });

    $this->tenantJson('PUT', "/api/dashboard/fields/{$this->field->id}/options/{$option->id}", ['label' => 'المعلم'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'duplicate_label');
});

test('BR-P08 an option is archived, never deleted, audited', function (): void {
    $option = $this->inTenant(fn () => ProductFieldOption::factory()->for($this->field, 'definition')->create());

    $this->tenantJson('POST', "/api/dashboard/fields/{$this->field->id}/options/{$option->id}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'archived');

    $this->assertDatabaseHas('product_field_options', ['id' => $option->id, 'status' => 'archived']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'field_option.archived', 'entity_id' => $option->id]);
});

test('an option of another field cannot be reached through this field', function (string $method, string $suffix): void {
    $option = $this->inTenant(fn () => ProductFieldOption::factory()->create(['label' => 'original']));

    $this->tenantJson($method, "/api/dashboard/fields/{$this->field->id}/options/{$option->id}{$suffix}", ['label' => 'hacked'])
        ->assertNotFound();

    $this->assertDatabaseHas('product_field_options', ['id' => $option->id, 'label' => 'original', 'status' => 'active']);
})->with([
    'update' => ['PUT', ''],
    'archive' => ['POST', '/archive'],
]);

test('BR-T04 adding an option to a field of another tenant is 404 and adds nothing', function (): void {
    $field = $this->inTenant(fn () => ProductFieldDefinition::factory()->select()->create(), $this->otherTenant());

    $this->tenantJson('POST', "/api/dashboard/fields/{$field->id}/options", ['label' => 'x'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseCount('product_field_options', 0);
});

test('BR-T04 an option of another tenant is 404 and untouched', function (string $method, string $suffix): void {
    [$field, $option] = $this->inTenant(function (): array {
        $field = ProductFieldDefinition::factory()->select()->create();

        return [$field, ProductFieldOption::factory()->for($field, 'definition')->create(['label' => 'original'])];
    }, $this->otherTenant());

    $this->tenantJson($method, "/api/dashboard/fields/{$field->id}/options/{$option->id}{$suffix}", ['label' => 'hacked'])
        ->assertNotFound();

    $this->assertDatabaseHas('product_field_options', ['id' => $option->id, 'label' => 'original', 'status' => 'active']);
})->with([
    'update' => ['PUT', ''],
    'archive' => ['POST', '/archive'],
]);
