<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\UpdateFieldDefinition;
use App\Modules\Catalog\Data\FieldDefinitionData;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Exceptions\FieldTypeLocked;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-P08 UpdateFieldDefinition keeps unsent flags and drops filterable when the type stops supporting it', function (): void {
    $field = ProductFieldDefinition::factory()->boolean()->filterable()->required()->create(['filter_order' => 4]);

    $updated = app(UpdateFieldDefinition::class)->handle($field, new FieldDefinitionData('نص', FieldType::Text));

    expect($updated->type)->toBe(FieldType::Text)
        ->and($updated->is_required)->toBeTrue()
        ->and($updated->is_filterable)->toBeFalse()
        ->and($updated->filter_order)->toBe(4)
        ->and(AuditLog::query()->sole()->new_values)->toMatchArray(['type' => 'text', 'is_filterable' => false]);
});

test('BR-P08 UpdateFieldDefinition locks the type once values exist', function (): void {
    $field = ProductFieldDefinition::factory()->create(['key' => 'isbn']);
    Product::factory()->create(['custom_fields' => ['isbn' => '978']]);

    app(UpdateFieldDefinition::class)->handle($field, new FieldDefinitionData('x', FieldType::Number));
})->throws(FieldTypeLocked::class);

test('BR-P08 UpdateFieldDefinition allows label changes while values exist', function (): void {
    $field = ProductFieldDefinition::factory()->create(['key' => 'isbn']);
    Product::factory()->create(['custom_fields' => ['isbn' => '978']]);

    expect(app(UpdateFieldDefinition::class)->handle($field, new FieldDefinitionData('ردمك', FieldType::Text))->label)->toBe('ردمك');
});

test('D69 UpdateFieldDefinition keeps the kind unless one is given, and null given means every section', function (): void {
    $field = ProductFieldDefinition::factory()->forKind(CategoryKind::Books)->create();

    $kept = app(UpdateFieldDefinition::class)->handle($field, new FieldDefinitionData('x', FieldType::Text))->kind;
    $cleared = app(UpdateFieldDefinition::class)->handle($field, new FieldDefinitionData('x', FieldType::Text, kindGiven: true))->kind;

    expect($kept)->toBe(CategoryKind::Books)
        ->and($cleared)->toBeNull()
        ->and(AuditLog::query()->latest('id')->firstOrFail()->old_values)->toMatchArray(['kind' => 'books']);
});

test('UpdateFieldDefinition refuses an explicit filterable text field', function (): void {
    $field = ProductFieldDefinition::factory()->create();

    app(UpdateFieldDefinition::class)->handle($field, new FieldDefinitionData('x', FieldType::Text, isFilterable: true));
})->throws(InvalidCatalogInput::class);
