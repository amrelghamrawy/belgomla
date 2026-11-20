<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Models\Sku;
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

test('D69 inside a category only the facets of its section and the section-less ones are offered', function (): void {
    $toys = Category::factory()->kind(CategoryKind::Toys)->create();
    $age = ProductFieldDefinition::factory()->select()->filterable()->forKind(CategoryKind::Toys)->create(['key' => 'age', 'filter_order' => 0]);
    $three = ProductFieldOption::factory()->for($age, 'definition')->create();
    $this->grade->update(['kind' => CategoryKind::Books]);
    Product::factory()->create(['category_id' => $toys->id, 'custom_fields' => ['age' => $three->id, 'grade' => $this->first->id, 'colored' => true]]);

    $facets = app(FacetQuery::class)->get(new ProductFilterData(categorySlug: $toys->slug));

    expect(array_column($facets, 'key'))->toBe(['age', 'colored']);
});

test('D69 without a category every section offers its facets', function (): void {
    $this->grade->update(['kind' => CategoryKind::Books]);

    expect(array_column(app(FacetQuery::class)->get(new ProductFilterData), 'key'))->toBe(['colored', 'grade']);
});

test('D69 an unknown category slug keeps every facet definition and counts nothing', function (): void {
    expect(app(FacetQuery::class)->get(new ProductFilterData(categorySlug: 'nope')))->toBe([]);
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

test('BR-P14 variant options follow the fields as one facet per folded name, values folded and counted per product', function (): void {
    $this->variantProduct(colours: ['أزرق', 'أسود'], code: 'PEN');
    $this->variantProduct(colours: ['أزرق', 'أحمر'], name: 'قلم رصاص', code: 'PCL');
    $marker = $this->variantProduct(colours: ['ازرق'], name: 'ماركر', code: 'MRK');
    $marker->options()->sole()->update(['name' => 'اللوْن']);

    $facets = app(FacetQuery::class)->get(new ProductFilterData);

    expect(array_column($facets, 'key'))->toBe(['colored', 'grade', 'o:اللون'])
        ->and($facets[2])->toBe(['key' => 'o:اللون', 'label' => 'اللون', 'type' => 'option', 'options' => [
            ['id' => 'ازرق', 'label' => 'أزرق', 'count' => 3],
            ['id' => 'احمر', 'label' => 'أحمر', 'count' => 1],
            ['id' => 'اسود', 'label' => 'أسود', 'count' => 1],
        ]]);
});

test('BR-P14 an inactive variant is not counted in the option facet', function (): void {
    $this->variantProduct(colours: ['أزرق', 'أسود']);
    Sku::query()->where('code', 'PEN-2')->update(['status' => 'inactive']);

    $facets = app(FacetQuery::class)->get(new ProductFilterData);

    expect($facets[2]['options'])->toBe([['id' => 'ازرق', 'label' => 'أزرق', 'count' => 1]]);
});

test('BR-P14 BR-P08 an option facet ignores its own selection and narrows the field facets', function (): void {
    $pen = $this->variantProduct(colours: ['أزرق', 'أسود'], code: 'PEN');
    $this->variantProduct(colours: ['أحمر'], name: 'قلم رصاص', code: 'PCL');
    $pen->update(['custom_fields' => ['grade' => $this->first->id]]);

    $facets = app(FacetQuery::class)->get(new ProductFilterData(fields: ['o:اللون' => 'أحمر']));

    expect(array_column($facets, 'key'))->toBe(['o:اللون'])
        ->and(array_column($facets[0]['options'], 'count', 'id'))->toBe(['احمر' => 1, 'ازرق' => 1, 'اسود' => 1]);
});

test('BR-P14 BR-P08 a field selection narrows the option facet counts', function (): void {
    $pen = $this->variantProduct(colours: ['أزرق', 'أسود'], code: 'PEN');
    $this->variantProduct(colours: ['أحمر'], name: 'قلم رصاص', code: 'PCL');
    $pen->update(['custom_fields' => ['grade' => $this->first->id]]);

    $facets = app(FacetQuery::class)->get(new ProductFilterData(fields: ['grade' => (string) $this->first->id]));

    expect($facets[2]['options'])->toBe([
        ['id' => 'ازرق', 'label' => 'أزرق', 'count' => 1],
        ['id' => 'اسود', 'label' => 'أسود', 'count' => 1],
    ]);
});

test('Tech §9b a variant option, value or sku change invalidates the cached counts', function (Closure $change): void {
    $this->variantProduct(colours: ['أزرق', 'أسود']);
    app(FacetQuery::class)->get(new ProductFilterData);

    $change();
    DB::enableQueryLog();
    app(FacetQuery::class)->get(new ProductFilterData);

    expect(DB::getQueryLog())->not->toBe([]);
})->with([
    'option renamed' => [fn () => ProductOption::query()->firstOrFail()->update(['name' => 'لون'])],
    'value renamed' => [fn () => ProductOptionValue::query()->firstOrFail()->update(['value' => 'كحلي'])],
    'variant deactivated' => [fn () => Sku::query()->where('code', 'PEN-2')->firstOrFail()->update(['status' => 'inactive'])],
]);
