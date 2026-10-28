<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\Sku;

const STORE_SKU_KEYS = [
    'id', 'code', 'unit', 'conversion_factor', 'moq', 'cover_price', 'base_price', 'discount_percent', 'tiers',
    'units' => ['*' => ['id', 'unit', 'conversion_factor', 'moq', 'cover_price', 'base_price', 'discount_percent', 'tiers', 'is_default', 'in_stock']],
    'available', 'in_stock', 'label', 'option_value_ids',
];

const STORE_PRODUCT_KEYS = [
    'id', 'name', 'slug', 'description', 'status', 'main_image_url', 'category' => ['id', 'name', 'kind'],
    'sku' => STORE_SKU_KEYS,
    'skus' => ['*' => STORE_SKU_KEYS],
    'options',
    'price_range' => ['min', 'max'],
    'preorder',
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
        $product = Product::factory()->withSku(['code' => 'ADW-1', 'cover_price' => '120', 'base_price' => '90', 'moq' => 5, 'conversion_factor' => 20], onHand: 140)
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
        ->assertJsonPath('data.0.sku.available', 140)
        ->assertJsonPath('data.0.sku.in_stock', true)
        ->assertJsonMissingPath('data.0.prices_hidden')
        ->assertJsonMissingPath('data.0.sku.coming_soon')
        ->assertJsonMissingPath('data.0.gallery');
});

test('D91 a product sold by the piece and by the carton shows both units with their own prices', function (): void {
    $product = $this->inTenant(fn (): Product => Product::factory()->withSku(['base_price' => '10.00', 'moq' => 6], onHand: 30)->create());
    $carton = $this->addUnit($this->inTenant(fn () => $product->skus()->sole()), ['conversion_factor' => 24, 'base_price' => '220.00'], [5 => '210.00']);

    $this->tenantJson('GET', "/api/store/products/{$product->slug}")
        ->assertOk()
        ->assertJsonCount(2, 'data.sku.units')
        ->assertJsonPath('data.sku.units.0.base_price', '10.00')
        ->assertJsonPath('data.sku.units.0.moq', 6)
        ->assertJsonPath('data.sku.units.0.in_stock', true)
        ->assertJsonPath('data.sku.units.1.id', $carton->id)
        ->assertJsonPath('data.sku.units.1.conversion_factor', 24)
        ->assertJsonPath('data.sku.units.1.base_price', '220.00')
        ->assertJsonPath('data.sku.units.1.tiers', [['min_quantity' => 5, 'unit_price' => '210.00']])
        ->assertJsonPath('data.sku.units.1.in_stock', true);
});

test('BR-P04 inactive products are hidden; coming soon is shown, not in stock and without available', function (): void {
    $comingSoon = $this->inTenant(function (): Product {
        Product::factory()->withSku(onHand: 50)->inactive()->create();

        return Product::factory()->withSku(onHand: 50)->comingSoon()->create();
    });

    $this->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $comingSoon->id)
        ->assertJsonPath('data.0.status', 'coming_soon')
        ->assertJsonPath('data.0.sku.in_stock', false)
        ->assertJsonPath('data.0.sku.coming_soon', true)
        ->assertJsonMissingPath('data.0.sku.available');
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

test('D69 a store product shows only the custom fields of its section and the section-less ones', function (): void {
    $this->inTenant(function (): void {
        $toys = Category::factory()->kind(CategoryKind::Toys)->create();
        ProductFieldDefinition::factory()->forKind(CategoryKind::Books)->create(['key' => 'publisher', 'label' => 'الشركة']);
        ProductFieldDefinition::factory()->forKind(CategoryKind::Toys)->create(['key' => 'age', 'label' => 'العمر']);
        ProductFieldDefinition::factory()->create(['key' => 'origin', 'label' => 'بلد المنشأ']);
        Product::factory()->withSku()->create(['category_id' => $toys->id, 'custom_fields' => ['publisher' => 'X', 'age' => '3+', 'origin' => 'مصر']]);
    });

    $this->tenantJson('GET', '/api/store/products')
        ->assertJsonPath('data.0.category.kind', 'toys')
        ->assertJsonPath('data.0.custom_fields', [
            ['key' => 'age', 'label' => 'العمر', 'value' => '3+'],
            ['key' => 'origin', 'label' => 'بلد المنشأ', 'value' => 'مصر'],
        ]);
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

test('BR-P14 a card stands for the first variant in stock: the second one when the first is sold out', function (): void {
    $this->variantProduct(colours: ['أزرق', 'أسود', 'أحمر'], stock: ['أزرق' => 0]);

    $this->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertJsonPath('data.0.sku.code', 'PEN-2')
        ->assertJsonPath('data.0.sku.label', 'أسود')
        ->assertJsonPath('data.0.sku.in_stock', true);
});

test('BR-P14 a product with every variant sold out stands for its first variant, not in stock', function (): void {
    $this->variantProduct(colours: ['أزرق', 'أسود'], stock: ['أزرق' => 0, 'أسود' => 0]);

    $this->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertJsonPath('data.0.sku.code', 'PEN-1')
        ->assertJsonPath('data.0.sku.in_stock', false);
});

test('BR-P14 the card shows the price range over the variants', function (): void {
    $this->variantProduct(colours: ['أزرق', 'أسود', 'أحمر'], prices: ['أزرق' => '12.00', 'أحمر' => '8.50']);

    $this->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertJsonPath('data.0.price_range', ['min' => '8.50', 'max' => '12.00']);
});

test('BR-P14 the price range leaves out an inactive variant', function (): void {
    $this->variantProduct(colours: ['أزرق', 'أسود', 'أحمر'], prices: ['أزرق' => '12.00', 'أحمر' => '8.50']);
    $this->inTenant(fn () => Sku::query()->where('code', 'PEN-3')->update(['status' => 'inactive']));

    $this->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertJsonPath('data.0.price_range', ['min' => '10.00', 'max' => '12.00']);
});

test('D1 hidden prices leave out the price range', function (): void {
    $this->tenant->settings->update(['require_login_for_prices' => true]);
    $this->variantProduct(prices: ['أزرق' => '12.00']);

    $this->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertJsonPath('data.0.prices_hidden', true)
        ->assertJsonMissingPath('data.0.price_range');
});

test('BR-P14 search matches the code of any variant', function (): void {
    $pen = $this->variantProduct(code: 'PEN');
    $this->inTenant(fn () => Product::factory()->withSku(['code' => 'NOTE-1'])->create(['name' => 'كشكول']));

    $this->tenantJson('GET', '/api/store/products?q=pen-3')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pen->id);
});

test('BR-P14 the option filter keeps the products with an active variant of that value, folding the spelling', function (string $query): void {
    $blue = $this->variantProduct(colours: ['أزرق', 'أسود'], code: 'PEN');
    $this->variantProduct(colours: ['أحمر'], name: 'قلم رصاص', code: 'PCL');

    $this->tenantJson('GET', '/api/store/products?'.$query)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $blue->id);
})->with([
    'folded name and value' => ['f[o:اللون]=ازرق'],
    'as spelled by the merchant' => ['f[o:اللون]=أزرق'],
]);

test('BR-P14 the option filter skips a product whose only variant of that value is inactive', function (): void {
    $this->variantProduct(colours: ['أزرق', 'أسود']);
    $this->inTenant(fn () => Sku::query()->where('code', 'PEN-1')->update(['status' => 'inactive']));

    $this->tenantJson('GET', '/api/store/products?f[o:اللون]=ازرق')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

test('BR-P14 the option filter combines with a custom field facet', function (): void {
    $match = $this->variantProduct(colours: ['أزرق'], code: 'PEN');
    $this->variantProduct(colours: ['أزرق'], name: 'قلم رصاص', code: 'PCL');
    $this->inTenant(fn () => $match->update(['custom_fields' => ['grade' => $this->firstPrep->id]]));

    $this->tenantJson('GET', "/api/store/products?f[o:اللون]=ازرق&f[grade]={$this->firstPrep->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

test('BR-P14 the price sort uses the cheapest active variant', function (string $sort, array $order): void {
    $ids = [
        'pen' => $this->variantProduct(colours: ['أزرق', 'أحمر', 'أسود'], prices: ['أزرق' => '30.00', 'أحمر' => '5.00', 'أسود' => '4.00'], code: 'PEN')->id,
        'pencil' => $this->inTenant(fn () => Product::factory()->withSku(['base_price' => '6.00'])->create(['name' => 'قلم رصاص'])->id),
        'eraser' => $this->inTenant(fn () => Product::factory()->withSku(['base_price' => '4.50'])->create(['name' => 'ممحاة'])->id),
    ];
    $this->inTenant(fn () => Sku::query()->where('code', 'PEN-3')->update(['status' => 'inactive']));

    $this->tenantJson('GET', "/api/store/products?sort={$sort}")
        ->assertJsonPath('data.*.id', array_map(fn (string $key): int => $ids[$key], $order));
})->with([
    'cheapest first' => ['price', ['eraser', 'pen', 'pencil']],
    'most expensive first' => ['-price', ['pencil', 'pen', 'eraser']],
]);
