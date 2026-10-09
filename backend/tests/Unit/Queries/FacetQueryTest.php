<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Queries\FacetQuery;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);

    $this->grade = ProductFieldDefinition::factory()->select()->filterable()->create(['key' => 'grade', 'filter_order' => 2]);
    $this->colored = ProductFieldDefinition::factory()->boolean()->filterable()->create(['key' => 'colored', 'label' => 'ملون', 'filter_order' => 1]);
    $this->first = ProductFieldOption::factory()->for($this->grade, 'definition')->create();
    Product::factory()->create(['custom_fields' => ['grade' => $this->first->id, 'colored' => true]]);
    Product::factory()->create(['custom_fields' => ['grade' => $this->first->id, 'colored' => false]]);
    Product::factory()->comingSoon()->create(['custom_fields' => ['colored' => true]]);
});

test('boolean facets offer yes and no with counts, coming soon products included', function (): void {
    $facets = app(FacetQuery::class)->get(new ProductFilterData);

    expect($facets[0])->toBe(['key' => 'colored', 'label' => 'ملون', 'type' => 'boolean', 'options' => [
        ['id' => 'true', 'label' => 'نعم', 'count' => 2],
        ['id' => 'false', 'label' => 'لا', 'count' => 1],
    ]]);
});

test('a facet without any matching product is left out', function (): void {
    $facets = app(FacetQuery::class)->get(new ProductFilterData(fields: ['colored' => 'true'], q: 'لا-يوجد'));

    expect($facets)->toBe([]);
});

test('archived, non-filterable and text fields are never facets', function (): void {
    ProductFieldDefinition::factory()->select()->filterable()->archived()->create(['key' => 'old']);
    ProductFieldDefinition::factory()->create(['key' => 'isbn', 'is_filterable' => true]);

    expect(array_column(app(FacetQuery::class)->get(new ProductFilterData), 'key'))->toBe(['colored', 'grade']);
});

test('Tech §9b counts are cached per selection: the second call runs no query', function (): void {
    $filter = new ProductFilterData(fields: ['colored' => 'true']);
    app(FacetQuery::class)->get($filter);

    DB::enableQueryLog();
    app(FacetQuery::class)->get($filter);

    expect(DB::getQueryLog())->toBe([]);
});

test('Tech §9b a field, option or category change invalidates the cached counts', function (Closure $change): void {
    app(FacetQuery::class)->get(new ProductFilterData);

    $change->call($this);
    DB::enableQueryLog();
    app(FacetQuery::class)->get(new ProductFilterData);

    expect(DB::getQueryLog())->not->toBe([]);
})->with([
    'field relabelled' => [fn () => $this->grade->update(['label' => 'الصف'])],
    'option added' => [fn () => ProductFieldOption::factory()->for($this->grade, 'definition')->create()],
    'category created' => [fn () => Category::factory()->create()],
    'product saved' => [fn () => Product::factory()->create()],
]);

test('cached counts are separate per tenant', function (): void {
    app(FacetQuery::class)->get(new ProductFilterData);

    $other = $this->otherTenant();
    $facets = $this->inTenant(fn () => app(FacetQuery::class)->get(new ProductFilterData), $other);

    expect($facets)->toBe([]);
});
