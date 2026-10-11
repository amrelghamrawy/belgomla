<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\FieldType;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $definition = $this->inTenant(fn () => ProductFieldDefinition::factory()->create()->fresh());

    expect(array_keys($definition->toArray()))->toBe([
        'id', 'tenant_id', 'key', 'label', 'type', 'is_required', 'show_on_storefront', 'is_filterable',
        'filter_order', 'sort_order', 'status', 'created_at', 'updated_at', 'kind',
    ]);
});

test('D69 a field applies to its own section or, without a kind, to every section', function (): void {
    [$books, $any] = $this->inTenant(fn (): array => [
        ProductFieldDefinition::factory()->forKind(CategoryKind::Books)->create()->fresh(),
        ProductFieldDefinition::factory()->create()->fresh(),
    ]);

    expect($books->kind)->toBe(CategoryKind::Books)
        ->and($books->appliesTo(CategoryKind::Books))->toBeTrue()
        ->and($books->appliesTo(CategoryKind::Toys))->toBeFalse()
        ->and($any->kind)->toBeNull()
        ->and($any->appliesTo(CategoryKind::Toys))->toBeTrue();
});

test('casts and defaults', function (): void {
    $definition = $this->inTenant(fn () => ProductFieldDefinition::query()
        ->create(['key' => 'brand', 'label' => 'الماركة', 'type' => 'select'])
        ->fresh());

    expect($definition->type)->toBe(FieldType::Select)
        ->and($definition->status)->toBe(ArchiveStatus::Active)
        ->and($definition->is_required)->toBeFalse()
        ->and($definition->show_on_storefront)->toBeTrue()
        ->and($definition->is_filterable)->toBeFalse()
        ->and($definition->filter_order)->toBe(0)
        ->and($definition->sort_order)->toBe(0);
});

test('options are ordered by sort order then id', function (): void {
    [$definition, $second, $first] = $this->inTenant(function (): array {
        $definition = ProductFieldDefinition::factory()->select()->create();

        return [
            $definition,
            ProductFieldOption::factory()->for($definition, 'definition')->create(['sort_order' => 2]),
            ProductFieldOption::factory()->for($definition, 'definition')->create(['sort_order' => 1]),
        ];
    });

    expect($definition->options->pluck('id')->all())->toBe([$first->id, $second->id]);
});

test('BR-P08 a field key is unique per tenant', function (): void {
    $this->inTenant(fn () => ProductFieldDefinition::factory()->create(['key' => 'grade']));

    $this->inTenant(fn () => ProductFieldDefinition::factory()->create(['key' => 'grade']));
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);
