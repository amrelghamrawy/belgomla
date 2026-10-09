<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Observers\FacetCacheObserver;
use App\Modules\Catalog\Services\FacetCacheVersion;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->versions = app(FacetCacheVersion::class);
});

test('saving or deleting a category, field or option bumps the facet cache version', function (Closure $make): void {
    $model = $make();
    $afterSave = $this->versions->current($this->tenant->id);

    $model->delete();

    expect($afterSave)->toBeGreaterThan(0)
        ->and($this->versions->current($this->tenant->id))->toBe($afterSave + 1);
})->with([
    'category' => [fn () => Category::factory()->create()],
    'field definition' => [fn () => ProductFieldDefinition::factory()->create()],
    'field option' => [fn () => ProductFieldOption::factory()->create()],
]);

test('a model without a tenant id is ignored by the facet observer', function (): void {
    app(FacetCacheObserver::class)->saved(new Category);

    expect($this->versions->current($this->tenant->id))->toBe(0);
});

test('the category observer keeps a given slug', function (): void {
    expect(Category::factory()->create(['slug' => 'custom'])->slug)->toBe('custom');
});
