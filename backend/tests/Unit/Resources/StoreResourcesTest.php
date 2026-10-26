<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Resources\FacetResource;
use App\Modules\Catalog\Http\Resources\PriceListRowResource;
use App\Modules\Catalog\Http\Resources\StoreProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Queries\StorefrontFieldsQuery;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('the store product resource key set with visible prices', function (): void {
    $product = Product::factory()->withSku(['cover_price' => '120', 'base_price' => '90'])->create()->load(['category', 'sku.sellingUnit', 'sku.tiers', 'sku.inventory', 'mainImage']);

    $data = rendered(new StoreProductResource($product, new Collection, false), storeRequest());

    expect(array_keys($data))->toBe(['id', 'name', 'slug', 'description', 'status', 'main_image_url', 'category', 'custom_fields', 'sku', 'preorder'])
        ->and($data['preorder'])->toBeNull()
        ->and(array_keys($data['sku']))->toBe(['id', 'code', 'unit', 'conversion_factor', 'moq', 'cover_price', 'base_price', 'discount_percent', 'tiers', 'available', 'in_stock'])
        ->and($data['sku']['unit'])->toBe($product->sku->sellingUnit->name);
});

test('D1 hidden prices drop the price keys and flag the product', function (): void {
    $product = Product::factory()->withSku()->create()->load('sku.tiers');

    $data = rendered(new StoreProductResource($product, new Collection, true), storeRequest());

    expect($data['prices_hidden'])->toBeTrue()
        ->and($data['sku'])->not->toHaveKeys(['cover_price', 'base_price', 'discount_percent', 'tiers']);
});

test('BR-PR10 the store sku shows the discount off the cover price and its tiers from the resolver', function (): void {
    $product = Product::factory()->withSku(['cover_price' => '120', 'base_price' => '90'])->create()->load('sku');
    App\Modules\Pricing\Models\SkuPriceTier::factory()->for($product->sku)->percent('30.00')->create(['min_quantity' => 10, 'unit_price' => '84.00']);
    $product->load('sku.tiers');

    $sku = rendered(new StoreProductResource($product, new Collection, false), storeRequest())['sku'];

    expect($sku['discount_percent'])->toBe('25.00')
        ->and($sku['tiers'])->toBe([['min_quantity' => 10, 'unit_price' => '84.00']]);
});

test('BR-P04 a coming soon product is flagged, not in stock and shows no available stock', function (): void {
    $product = Product::factory()->withSku(onHand: 50)->comingSoon()->create()->load('sku.inventory');

    $sku = rendered(new StoreProductResource($product, new Collection, false), storeRequest())['sku'];

    expect($sku['in_stock'])->toBeFalse()
        ->and($sku['coming_soon'])->toBeTrue()
        ->and($sku)->not->toHaveKey('available');
});

test('D37 an active sku is in stock while at least one selling unit is available', function (int $onHand, int $reserved, int $available, bool $inStock): void {
    $product = Product::factory()->withSku(['conversion_factor' => 20], $onHand, $reserved)->create()->load('sku.inventory');

    $sku = rendered(new StoreProductResource($product, new Collection, false), storeRequest())['sku'];

    expect($sku['available'])->toBe($available)
        ->and($sku['in_stock'])->toBe($inStock);
})->with([
    'BR-INV02 available is on hand minus reserved' => [100, 30, 70, true],
    'exactly one carton' => [20, 0, 20, true],
    'one piece short of a carton' => [39, 20, 19, false],
    'nothing left' => [20, 20, 0, false],
]);

test('BR-INV02 a sku without a stock row has nothing available', function (): void {
    $product = Product::factory()->withSku()->create()->load('sku.inventory');

    $sku = rendered(new StoreProductResource($product, new Collection, false), storeRequest())['sku'];

    expect($sku['available'])->toBe(0)
        ->and($sku['in_stock'])->toBeFalse();
});

test('BR-P08 custom fields render per type with labels, unknown option ids are skipped', function (): void {
    $grade = ProductFieldDefinition::factory()->select()->create(['key' => 'grade', 'label' => 'السنة', 'sort_order' => 1]);
    $first = ProductFieldOption::factory()->for($grade, 'definition')->create(['label' => 'أولى']);
    ProductFieldDefinition::factory()->number()->create(['key' => 'pages', 'label' => 'صفحات', 'sort_order' => 2]);
    ProductFieldDefinition::factory()->boolean()->create(['key' => 'colored', 'label' => 'ملون', 'sort_order' => 3]);
    ProductFieldDefinition::factory()->select()->create(['key' => 'publisher', 'sort_order' => 4]);
    $product = Product::factory()->create(['custom_fields' => [
        'grade' => $first->id, 'pages' => 96, 'colored' => false, 'publisher' => 999_999,
    ]]);

    $data = rendered(new StoreProductResource($product, app(StorefrontFieldsQuery::class)->get(), false), storeRequest());

    expect($data['custom_fields'])->toBe([
        ['key' => 'grade', 'label' => 'السنة', 'value' => 'أولى'],
        ['key' => 'pages', 'label' => 'صفحات', 'value' => '96'],
        ['key' => 'colored', 'label' => 'ملون', 'value' => 'لا'],
    ]);
});

test('a product without loaded relations renders nulls instead of lazy-loading', function (): void {
    $product = Product::factory()->withSku()->create()->refresh();

    $data = rendered(new StoreProductResource($product, new Collection, false), storeRequest());

    expect($data['category'])->toBeNull()
        ->and($data['sku'])->toBeNull()
        ->and($data['main_image_url'])->toBeNull();
});

test('the facet resource key set', function (): void {
    $facet = ['key' => 'grade', 'label' => 'السنة', 'type' => 'select', 'options' => [['id' => 1, 'label' => 'أولى', 'count' => 3]]];

    expect(rendered(new FacetResource($facet), storeRequest()))->toBe($facet);
});

test('BR-P13 the price list row key set, with and without prices', function (): void {
    $product = Product::factory()->withSku(['code' => 'A-1', 'cover_price' => '10', 'base_price' => '8'])->create()->load(['category', 'sku.sellingUnit']);

    $visible = rendered(new PriceListRowResource($product, false), storeRequest());
    $hidden = rendered(new PriceListRowResource($product, true), storeRequest());

    expect(array_keys($visible))->toBe(['id', 'name', 'slug', 'kind', 'code', 'unit', 'moq', 'cover_price', 'base_price', 'availability'])
        ->and($visible['kind'])->toBe('general')
        ->and($visible['availability'])->toBe('available')
        ->and($hidden)->not->toHaveKeys(['cover_price', 'base_price']);
});

test('BR-O14 a coming soon product shows its pre-order offer; the remaining quantity follows the cap and what is pre-ordered', function (): void {
    $product = Product::factory()->withSku(['conversion_factor' => 1])->preorderable(limit: 30, expectedAt: '2026-12-01')->create()->load('sku');
    App\Modules\Orders\Models\Order::factory()->preorder()->withItems([['sku_id' => $product->sku->id, 'quantity' => 12, 'reserved' => false]])->create();

    $data = rendered(new StoreProductResource($product, new Collection, false), storeRequest());

    expect($data['preorder'])->toBe(['enabled' => true, 'expected_at' => '2026-12-01', 'remaining' => 18]);
});

test('BR-O14 a coming soon product without pre-orders, or without a cap, has no remaining quantity', function (): void {
    $closed = Product::factory()->withSku()->comingSoon()->create();
    $uncapped = Product::factory()->withSku()->preorderable(expectedAt: null)->create();

    expect(rendered(new StoreProductResource($closed, new Collection, false), storeRequest())['preorder'])->toBe(['enabled' => false, 'expected_at' => null, 'remaining' => null])
        ->and(rendered(new StoreProductResource($uncapped, new Collection, false), storeRequest())['preorder'])->toBe(['enabled' => true, 'expected_at' => null, 'remaining' => null]);
});

test('BR-PR14 a store sku at the customer\'s own price carries price_source; a plain one does not', function (): void {
    $product = Product::factory()->withSku(['cover_price' => '120', 'base_price' => '90'])->create()->load(['sku.tiers']);

    $own = rendered(new StoreProductResource($product, new Collection, false, new PricingContext(null, [$product->sku->id => '80.00'])), storeRequest())['sku'];
    $plain = rendered(new StoreProductResource($product, new Collection, false, new PricingContext), storeRequest())['sku'];

    expect($own['base_price'])->toBe('80.00')
        ->and($own['discount_percent'])->toBe('33.33')
        ->and($own['price_source'])->toBe('customer')
        ->and($plain)->not->toHaveKey('price_source')
        ->and($plain['base_price'])->toBe('90.00');
});

test('BR-PR14 related products are shown at the same customer\'s prices', function (): void {
    $product = Product::factory()->withSku(['base_price' => '90'])->create();
    $related = Product::factory()->withSku(['base_price' => '50'])->create();
    App\Modules\Catalog\Models\ProductRelation::factory()->create(['product_id' => $product->id, 'related_product_id' => $related->id]);
    $product->load(['sku.tiers', 'relations.relatedProduct.sku.tiers', 'incomingRelations']);

    $data = rendered(new StoreProductResource($product, new Collection, false, new PricingContext('10.00')), storeRequest());

    expect($data['related'][0]['product']['sku']['base_price'])->toBe('45.00')
        ->and($data['related'][0]['product']['sku']['price_source'])->toBe('group');
});

test('BR-PR14 a price list row shows the customer\'s wholesale price and its source', function (): void {
    $product = Product::factory()->withSku(['base_price' => '80'])->create()->load(['category', 'sku.sellingUnit', 'sku.tiers']);

    $row = rendered(new PriceListRowResource($product, false, new PricingContext('25.00')), storeRequest());
    $hidden = rendered(new PriceListRowResource($product, true, new PricingContext('25.00')), storeRequest());

    expect($row['base_price'])->toBe('60.00')
        ->and($row['price_source'])->toBe('group')
        ->and($hidden)->not->toHaveKeys(['base_price', 'price_source']);
});

function storePromotion(int $promotionId = 5): App\Modules\Pricing\Data\PromotionPrice
{
    return new App\Modules\Pricing\Data\PromotionPrice($promotionId, 'عرض الأضواء', '20.00', Carbon\CarbonImmutable::parse('2026-11-30 22:00:00'));
}

test('BR-PR15 a store sku priced by a promotion carries the promotion; prices hidden hide it', function (): void {
    $product = Product::factory()->withSku(['cover_price' => '120', 'base_price' => '90'])->create()->load(['sku.tiers']);
    $context = new PricingContext(promotions: [$product->sku->id => storePromotion()]);

    $sku = rendered(new StoreProductResource($product, new Collection, false, $context), storeRequest())['sku'];
    $hidden = rendered(new StoreProductResource($product, new Collection, true, $context), storeRequest())['sku'];

    expect($sku['base_price'])->toBe('72.00')
        ->and($sku['promotion'])->toBe(['id' => 5, 'name' => 'عرض الأضواء', 'discount_percent' => '20.00', 'ends_at' => '2026-11-30T22:00:00+00:00'])
        ->and($sku)->not->toHaveKey('price_source')
        ->and($hidden)->not->toHaveKeys(['base_price', 'promotion']);
});

test('BR-PR15 a store sku whose group price beats the promotion shows no promotion', function (): void {
    $product = Product::factory()->withSku(['base_price' => '90'])->create()->load(['sku.tiers']);

    $sku = rendered(new StoreProductResource($product, new Collection, false, new PricingContext('30.00', [], [$product->sku->id => storePromotion()])), storeRequest())['sku'];

    expect($sku['price_source'])->toBe('group')
        ->and($sku)->not->toHaveKey('promotion');
});

test('BR-PR15 a price list row priced by a promotion carries the promotion', function (): void {
    $product = Product::factory()->withSku(['base_price' => '80'])->create()->load(['category', 'sku.sellingUnit', 'sku.tiers']);

    $row = rendered(new PriceListRowResource($product, false, new PricingContext(promotions: [$product->sku->id => storePromotion()])), storeRequest());

    expect($row['base_price'])->toBe('64.00')
        ->and($row['promotion']['id'])->toBe(5);
});

test('BR-PR15 the sku ids a page of products and its related products show', function (): void {
    $product = Product::factory()->withSku()->create();
    $related = Product::factory()->withSku()->create();
    $incoming = Product::factory()->withSku()->create();
    App\Modules\Catalog\Models\ProductRelation::factory()->create(['product_id' => $product->id, 'related_product_id' => $related->id]);
    App\Modules\Catalog\Models\ProductRelation::factory()->create(['product_id' => $incoming->id, 'related_product_id' => $product->id]);
    $product->load(['sku', 'relations.relatedProduct.sku', 'incomingRelations.product.sku']);
    $bare = Product::factory()->create();

    expect(StoreProductResource::skuIds([$product, $bare]))->toBe([$product->sku->id, $related->sku->id, $incoming->sku->id])
        ->and(PriceListRowResource::skuIds(new Collection([$related->load('sku'), $bare->load('sku')])))->toBe([$related->sku->id]);
});
