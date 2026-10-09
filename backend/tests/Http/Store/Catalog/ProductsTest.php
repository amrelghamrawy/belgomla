<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\ProductImage;

const STORE_PRODUCT_KEYS = [
    'id', 'name', 'slug', 'description', 'status', 'main_image_url', 'category' => ['id', 'name'],
    'sku' => ['id', 'code', 'unit', 'conversion_factor', 'moq', 'cover_price', 'base_price', 'in_stock'],
];

beforeEach(function (): void {
    [$this->grade, $this->firstPrep, $this->secondPrep] = $this->inTenant(function (): array {
        $grade = ProductFieldDefinition::factory()->select()->filterable()->create(['key' => 'grade', 'label' => 'السنة الدراسية']);

        return [
            $grade,
            ProductFieldOption::factory()->for($grade, 'definition')->create(['label' => 'أولى إعدادي']),
            ProductFieldOption::factory()->for($grade, 'definition')->create(['label' => 'ثانية إعدادي']),
        ];
    });
});

test('the store lists visible products in the documented shape with laravel pagination', function (): void {
    $product = $this->inTenant(function (): Product {
        $product = Product::factory()->withSku(['code' => 'ADW-1', 'cover_price' => '120', 'base_price' => '90', 'moq' => 5, 'conversion_factor' => 20])
            ->create(['custom_fields' => ['grade' => $this->firstPrep->id]]);
        ProductImage::factory()->for($product)->main()->create();

        return $product;
    });

    $this->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertExactJsonStructure([
            'data' => ['*' => [...STORE_PRODUCT_KEYS, 'custom_fields' => ['*' => ['key', 'label', 'value']]]],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'],
        ])
        ->assertJsonPath('data.0.id', $product->id)
        ->assertJsonPath('data.0.custom_fields', [['key' => 'grade', 'label' => 'السنة الدراسية', 'value' => 'أولى إعدادي']])
        ->assertJsonPath('data.0.sku.code', 'ADW-1')
        ->assertJsonPath('data.0.sku.cover_price', '120.00')
        ->assertJsonPath('data.0.sku.base_price', '90.00')
        ->assertJsonPath('data.0.sku.moq', 5)
        ->assertJsonPath('data.0.sku.in_stock', true)
        ->assertJsonMissingPath('data.0.prices_hidden')
        ->assertJsonMissingPath('data.0.sku.coming_soon')
        ->assertJsonMissingPath('data.0.gallery');
});

test('BR-P04 inactive products are hidden; coming soon is shown, not in stock', function (): void {
    $comingSoon = $this->inTenant(function (): Product {
        Product::factory()->withSku()->inactive()->create();

        return Product::factory()->withSku()->comingSoon()->create();
    });

    $this->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $comingSoon->id)
        ->assertJsonPath('data.0.status', 'coming_soon')
        ->assertJsonPath('data.0.sku.in_stock', false)
        ->assertJsonPath('data.0.sku.coming_soon', true);
});

test('BR-P08 custom fields not shown on the storefront and archived fields are left out', function (): void {
    $this->inTenant(function (): void {
        ProductFieldDefinition::factory()->hidden()->create(['key' => 'cost_code']);
        ProductFieldDefinition::factory()->archived()->create(['key' => 'old']);
        ProductFieldDefinition::factory()->boolean()->create(['key' => 'colored', 'label' => 'ملون']);
        Product::factory()->withSku()->create(['custom_fields' => ['cost_code' => 'X', 'old' => 'Y', 'colored' => true]]);
    });

    $this->tenantJson('GET', '/api/store/products')
        ->assertJsonPath('data.0.custom_fields', [['key' => 'colored', 'label' => 'ملون', 'value' => 'نعم']]);
});

test('D1 prices are hidden from guests when the tenant requires login for prices', function (): void {
    $this->tenant->settings->update(['require_login_for_prices' => true]);
    $this->inTenant(fn () => Product::factory()->withSku(['cover_price' => '120'])->create());

    $this->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertJsonPath('data.0.prices_hidden', true)
        ->assertJsonMissingPath('data.0.sku.base_price')
        ->assertJsonMissingPath('data.0.sku.cover_price')
        ->assertJsonPath('data.0.sku.moq', 1);
});

test('BR-P06 the category slug filter includes descendants', function (): void {
    [$root, $inChild] = $this->inTenant(function (): array {
        $root = Category::factory()->create(['name' => 'إعدادي']);
        $child = Category::factory()->childOf($root)->create();
        Product::factory()->withSku()->create();

        return [$root, Product::factory()->withSku()->create(['category_id' => $child->id])];
    });

    $this->tenantJson('GET', '/api/store/products?category='.urlencode($root->slug))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inChild->id);
});

test('BR-P08 facets in the url filter with AND and search folds colloquial spelling', function (string $query): void {
    $match = $this->inTenant(function (): Product {
        Product::factory()->withSku()->create(['name' => 'علوم', 'custom_fields' => ['grade' => $this->firstPrep->id]]);
        Product::factory()->withSku()->create(['name' => 'رياضيات', 'custom_fields' => ['grade' => $this->secondPrep->id]]);

        return Product::factory()->withSku()->create(['name' => 'علوم', 'custom_fields' => ['grade' => $this->secondPrep->id]]);
    });

    $uri = '/api/store/products?'.str_replace('{grade}', (string) $this->secondPrep->id, $query);

    $this->tenantJson('GET', $uri)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
})->with([
    'facet plus name' => ['f[grade]={grade}&q=علوم'],
    'colloquial search over the option label' => ['q=علوم تانية اعدادي'],
]);

test('the store sorts by name and price', function (string $sort, array $order): void {
    $ids = $this->inTenant(fn (): array => [
        'b' => Product::factory()->withSku(['base_price' => '10.00'])->create(['name' => 'ب'])->id,
        'a' => Product::factory()->withSku(['base_price' => '20.00'])->create(['name' => 'أ'])->id,
    ]);

    $this->tenantJson('GET', "/api/store/products?sort={$sort}")
        ->assertJsonPath('data.0.id', $ids[$order[0]])
        ->assertJsonPath('data.1.id', $ids[$order[1]]);
})->with([
    'name' => ['name', ['a', 'b']],
    'cheapest first' => ['price', ['b', 'a']],
    'most expensive first' => ['-price', ['a', 'b']],
]);

test('store list validation', function (string $query, string $field): void {
    $this->tenantJson('GET', "/api/store/products?{$query}")
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'unknown sort' => ['sort=-name', 'sort'],
    'status is not a store filter' => ['status=inactive', 'status'],
    'facets must be a map' => ['f=1', 'f'],
    'search too long' => ['q='.str_repeat('a', 101), 'q'],
]);

test('BR-T05 the store never lists products of another tenant', function (): void {
    $this->inTenant(fn () => Product::factory()->withSku()->create(), $this->otherTenant());

    $this->tenantJson('GET', '/api/store/products')->assertOk()->assertJsonPath('meta.total', 0);
});
