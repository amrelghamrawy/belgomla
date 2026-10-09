<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Queries\FilteredProductsQuery;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);

    $this->grade = ProductFieldDefinition::factory()->select()->filterable()->create(['key' => 'grade']);
    $this->publisher = ProductFieldDefinition::factory()->select()->filterable()->create(['key' => 'publisher']);
    $this->colored = ProductFieldDefinition::factory()->boolean()->filterable()->create(['key' => 'colored']);
    $this->secondPrep = ProductFieldOption::factory()->for($this->grade, 'definition')->create(['label' => 'ثانية إعدادي']);
    $this->firstPrep = ProductFieldOption::factory()->for($this->grade, 'definition')->create(['label' => 'أولى إعدادي']);
    $this->adwaa = ProductFieldOption::factory()->for($this->publisher, 'definition')->create(['label' => 'الأضواء']);
    $this->moalem = ProductFieldOption::factory()->for($this->publisher, 'definition')->create(['label' => 'المعلم']);
});

/**
 * @return list<int>
 */
function filteredIds(ProductFilterData $filter): array
{
    return app(FilteredProductsQuery::class)->builder($filter)->orderBy('id')->pluck('id')->all();
}

test('BR-P08 search finds "علوم تانية اعدادي الأضواء" through the name and the option labels', function (): void {
    $book = Product::factory()->withSku()->create([
        'name' => 'عُلوم',
        'custom_fields' => ['grade' => $this->secondPrep->id, 'publisher' => $this->adwaa->id],
    ]);
    Product::factory()->withSku()->create(['name' => 'علوم', 'custom_fields' => ['grade' => $this->firstPrep->id, 'publisher' => $this->adwaa->id]]);
    Product::factory()->withSku()->create(['name' => 'علوم', 'custom_fields' => ['grade' => $this->secondPrep->id, 'publisher' => $this->moalem->id]]);

    expect(filteredIds(new ProductFilterData(q: 'علوم تانية اعدادي الأضواء')))->toBe([$book->id]);
});

test('BR-P08 search matches the sku code, case-insensitively', function (): void {
    $product = Product::factory()->withSku(['code' => 'ADW-SCI-P1'])->create();
    Product::factory()->withSku(['code' => 'MOA-MATH'])->create();

    expect(filteredIds(new ProductFilterData(q: 'adw-sci')))->toBe([$product->id]);
});

test('search treats like wildcards literally', function (): void {
    Product::factory()->withSku()->create(['name' => 'قلم']);
    $percent = Product::factory()->withSku()->create(['name' => 'خصم 50%']);

    expect(filteredIds(new ProductFilterData(q: '%')))->toBe([$percent->id]);
});

test('labels of options that belong to a text field value do not leak into search', function (): void {
    ProductFieldDefinition::factory()->create(['key' => 'note']);
    $product = Product::factory()->withSku()->create(['name' => 'قلم', 'custom_fields' => ['note' => (string) $this->adwaa->id]]);

    expect(filteredIds(new ProductFilterData(q: 'الأضواء')))->not->toContain($product->id);
});

test('BR-P06 a category filter includes all descendants', function (): void {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $grandchild = Category::factory()->childOf($child)->create();
    $inRoot = Product::factory()->create(['category_id' => $root->id]);
    $inGrandchild = Product::factory()->create(['category_id' => $grandchild->id]);
    Product::factory()->create();

    expect(filteredIds(new ProductFilterData(categoryId: $root->id)))->toBe([$inRoot->id, $inGrandchild->id])
        ->and(filteredIds(new ProductFilterData(categorySlug: $child->slug)))->toBe([$inGrandchild->id]);
});

test('an unknown category matches nothing', function (): void {
    Product::factory()->create();

    expect(filteredIds(new ProductFilterData(categorySlug: 'nope')))->toBe([])
        ->and(filteredIds(new ProductFilterData(categoryId: 999_999)))->toBe([]);
});

test('BR-P08 field filters combine with AND', function (): void {
    $match = Product::factory()->create(['custom_fields' => ['grade' => $this->firstPrep->id, 'publisher' => $this->adwaa->id]]);
    Product::factory()->create(['custom_fields' => ['grade' => $this->firstPrep->id, 'publisher' => $this->moalem->id]]);
    Product::factory()->create(['custom_fields' => ['grade' => $this->secondPrep->id, 'publisher' => $this->adwaa->id]]);

    expect(filteredIds(new ProductFilterData(fields: ['grade' => (string) $this->firstPrep->id, 'publisher' => (string) $this->adwaa->id])))->toBe([$match->id]);
});

test('boolean filters accept true and false', function (): void {
    $colored = Product::factory()->create(['custom_fields' => ['colored' => true]]);
    $plain = Product::factory()->create(['custom_fields' => ['colored' => false]]);

    expect(filteredIds(new ProductFilterData(fields: ['colored' => 'true'])))->toBe([$colored->id])
        ->and(filteredIds(new ProductFilterData(fields: ['colored' => 'false'])))->toBe([$plain->id]);
});

test('an unknown filter key or a malformed value matches nothing', function (array $fields): void {
    Product::factory()->create(['custom_fields' => ['grade' => $this->firstPrep->id]]);

    expect(filteredIds(new ProductFilterData(fields: $fields)))->toBe([]);
})->with([
    'unknown key' => [['brand' => '1']],
    'select value not an id' => [['grade' => 'first']],
    'boolean value not a boolean' => [['colored' => 'maybe']],
]);

test('number and text fields filter by equality', function (): void {
    ProductFieldDefinition::factory()->number()->create(['key' => 'pages']);
    ProductFieldDefinition::factory()->create(['key' => 'isbn']);
    $match = Product::factory()->create(['custom_fields' => ['pages' => 120, 'isbn' => '978']]);
    Product::factory()->create(['custom_fields' => ['pages' => 80, 'isbn' => '978']]);

    expect(filteredIds(new ProductFilterData(fields: ['pages' => '120', 'isbn' => '978'])))->toBe([$match->id])
        ->and(filteredIds(new ProductFilterData(fields: ['pages' => 'many'])))->toBe([]);
});

test('the status filter and the tenant scope apply', function (): void {
    $inactive = Product::factory()->inactive()->create();
    Product::factory()->create();
    $this->inTenant(fn () => Product::factory()->inactive()->create(), $this->otherTenant());

    expect(filteredIds(new ProductFilterData(status: ProductStatus::Inactive)))->toBe([$inactive->id]);
});
