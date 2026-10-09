<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $option = $this->inTenant(fn () => ProductFieldOption::factory()->create()->fresh());

    expect(array_keys($option->toArray()))->toBe([
        'id', 'tenant_id', 'definition_id', 'label', 'sort_order', 'status', 'created_at', 'updated_at',
    ]);
});

test('casts', function (): void {
    $option = $this->inTenant(fn () => ProductFieldOption::factory()->archived()->create(['sort_order' => '4'])->fresh());

    expect($option->status)->toBe(ArchiveStatus::Archived)
        ->and($option->sort_order)->toBe(4);
});

test('belongs to its definition', function (): void {
    [$definition, $option] = $this->inTenant(function (): array {
        $definition = ProductFieldDefinition::factory()->select()->create();

        return [$definition, ProductFieldOption::factory()->for($definition, 'definition')->create()];
    });

    expect($option->definition->is($definition))->toBeTrue();
});

test('BR-P08 an option label is unique within its definition', function (): void {
    $this->inTenant(function (): void {
        $definition = ProductFieldDefinition::factory()->select()->create();
        ProductFieldOption::factory()->for($definition, 'definition')->create(['label' => 'الأضواء']);
        ProductFieldOption::factory()->for($definition, 'definition')->create(['label' => 'الأضواء']);
    });
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);
