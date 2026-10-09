<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\UpdateFieldOption;
use App\Modules\Catalog\Exceptions\CatalogConflict;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('UpdateFieldOption allows the same label within the same option and refuses a sibling label', function (): void {
    $field = ProductFieldDefinition::factory()->select()->create();
    ProductFieldOption::factory()->for($field, 'definition')->create(['label' => 'أ']);
    $option = ProductFieldOption::factory()->for($field, 'definition')->create(['label' => 'ب']);

    expect(app(UpdateFieldOption::class)->handle($option, 'ب', 3)->sort_order)->toBe(3)
        ->and(fn () => app(UpdateFieldOption::class)->handle($option, 'أ', null))->toThrow(CatalogConflict::class);
});
