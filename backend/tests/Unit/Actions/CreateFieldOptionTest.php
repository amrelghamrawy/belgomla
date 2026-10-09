<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\CreateFieldOption;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('CreateFieldOption appends after the highest sort order and ignores other fields', function (): void {
    $field = ProductFieldDefinition::factory()->select()->create();
    ProductFieldOption::factory()->for($field, 'definition')->create(['sort_order' => 7]);
    ProductFieldOption::factory()->create(['sort_order' => 50]);

    expect(app(CreateFieldOption::class)->handle($field, 'جديد')->sort_order)->toBe(8);
});

test('CreateFieldOption starts at 1 on an empty field', function (): void {
    $field = ProductFieldDefinition::factory()->select()->create();

    expect(app(CreateFieldOption::class)->handle($field, 'أول')->sort_order)->toBe(1);
});

test('CreateFieldOption guards', function (string $type, string $label, string $exception): void {
    $field = ProductFieldDefinition::factory()->create(['type' => $type]);
    ProductFieldOption::factory()->create(['definition_id' => $field->id, 'label' => 'موجود']);

    app(CreateFieldOption::class)->handle($field, $label);
})->with([
    'not a select field' => ['text', 'x', InvalidCatalogInput::class],
    'duplicate label' => ['select', 'موجود', CatalogConflict::class],
])->throws(App\Support\ApiException::class);
