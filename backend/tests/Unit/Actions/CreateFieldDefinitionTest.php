<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Actions\CreateFieldDefinition;
use App\Modules\Catalog\Data\FieldDefinitionData;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('CreateFieldDefinition applies defaults and creates options in order', function (): void {
    $field = app(CreateFieldDefinition::class)->handle(new FieldDefinitionData('الترم', FieldType::Select, key: 'term', options: ['الأول', 'الثاني']));

    expect($field->is_required)->toBeFalse()
        ->and($field->kind)->toBeNull()
        ->and($field->show_on_storefront)->toBeTrue()
        ->and($field->is_filterable)->toBeFalse()
        ->and($field->options->pluck('label', 'sort_order')->all())->toBe([1 => 'الأول', 2 => 'الثاني'])
        ->and(AuditLog::query()->sole()->new_values)->toMatchArray(['key' => 'term', 'options' => ['الأول', 'الثاني']]);
});

test('D69 CreateFieldDefinition binds the field to a section, audited', function (): void {
    $field = app(CreateFieldDefinition::class)->handle(new FieldDefinitionData('العمر', FieldType::Text, key: 'age', kind: CategoryKind::Toys));

    expect($field->kind)->toBe(CategoryKind::Toys)
        ->and(AuditLog::query()->sole()->new_values)->toMatchArray(['kind' => 'toys']);
});

test('CreateFieldDefinition needs a key', function (): void {
    app(CreateFieldDefinition::class)->handle(new FieldDefinitionData('x', FieldType::Text));
})->throws(RuntimeException::class, 'needs a key');

test('BR-P08 CreateFieldDefinition guards', function (FieldDefinitionData $data, string $exception): void {
    ProductFieldDefinition::factory()->create(['key' => 'taken']);

    expect(fn () => app(CreateFieldDefinition::class)->handle($data))->toThrow($exception);

    expect(ProductFieldDefinition::query()->count())->toBe(1);
})->with([
    'duplicate key' => fn (): array => [new FieldDefinitionData('x', FieldType::Text, key: 'taken'), CatalogConflict::class],
    'filterable number' => fn (): array => [new FieldDefinitionData('x', FieldType::Number, key: 'n', isFilterable: true), InvalidCatalogInput::class],
    'options on boolean' => fn (): array => [new FieldDefinitionData('x', FieldType::Boolean, key: 'b', options: ['a']), InvalidCatalogInput::class],
]);
