<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Resources\FacetResource;
use App\Modules\Catalog\Http\Resources\PriceListRowResource;
use App\Modules\Catalog\Http\Resources\StoreProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Queries\StorefrontFieldsQuery;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('the store product resource key set with visible prices', function (): void {
    $product = Product::factory()->withSku(['cover_price' => '120', 'base_price' => '90'])->create()->load(['category', 'activeSkus.activeUnits.unit', 'activeSkus.activeUnits.tiers', 'activeSkus.inventory', 'mainImage']);

    $data = rendered(new StoreProductResource($product, new Collection, false), storeRequest());

    expect(array_keys($data))->toBe(['id', 'name', 'slug', 'description', 'status', 'type', 'main_image_url', 'category', 'custom_fields', 'sku', 'skus', 'options', 'price_range', 'preorder'])
        ->and($data['preorder'])->toBeNull()
        ->and(array_keys($data['sku']))->toBe(['id', 'code', 'unit', 'conversion_factor', 'moq', 'cover_price', 'base_price', 'discount_percent', 'tiers', 'units', 'available', 'in_stock', 'label', 'option_value_ids'])
        ->and(array_keys($data['sku']['units'][0]))->toBe(['id', 'unit', 'conversion_factor', 'moq', 'cover_price', 'base_price', 'discount_percent', 'tiers', 'is_default', 'in_stock'])
        ->and($data['sku']['unit'])->toBe($product->activeSkus->first()?->activeUnits->sole()->unit->name);
});

test('D1 hidden prices drop the price keys and flag the product', function (): void {
    $product = Product::factory()->withSku()->create()->load('activeSkus.activeUnits.tiers');

    $data = rendered(new StoreProductResource($product, new Collection, true), storeRequest());

    expect($data['prices_hidden'])->toBeTrue()
        ->and($data['sku'])->not->toHaveKeys(['cover_price', 'base_price', 'discount_percent', 'tiers']);
});

test('BR-PR10 the store sku shows the discount off the cover price and its tiers from the resolver', function (): void {
    $product = Product::factory()->withSku(['cover_price' => '120', 'base_price' => '90'])->create();
    App\Modules\Pricing\Models\SkuPriceTier::factory()->percent('30.00')->create(['sku_id' => $product->skus()->sole()->id, 'min_quantity' => 10, 'unit_price' => '84.00']);
    $product->load('activeSkus.activeUnits.tiers');

    $sku = rendered(new StoreProductResource($product, new Collection, false), storeRequest())['sku'];

    expect($sku['discount_percent'])->toBe('25.00')
        ->and($sku['tiers'])->toBe([['min_quantity' => 10, 'unit_price' => '84.00']]);
});

test('BR-P04 a coming soon product is flagged, not in stock and shows no available stock', function (): void {
    $product = Product::factory()->withSku(onHand: 50)->comingSoon()->create()->load('activeSkus.inventory', 'activeSkus.activeUnits');

    $sku = rendered(new StoreProductResource($product, new Collection, false), storeRequest())['sku'];

    expect($sku['in_stock'])->toBeFalse()
        ->and($sku['coming_soon'])->toBeTrue()
        ->and($sku)->not->toHaveKey('available');
});

test('D37 an active sku is in stock while at least one selling unit is available', function (int $onHand, int $reserved, int $available, bool $inStock): void {
    $product = Product::factory()->withSku(['conversion_factor' => 20], $onHand, $reserved)->create()->load('activeSkus.inventory', 'activeSkus.activeUnits');

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
    $product = Product::factory()->withSku()->create()->load('activeSkus.inventory', 'activeSkus.activeUnits');

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
    $product = Product::factory()->withSku(['code' => 'A-1', 'cover_price' => '10', 'base_price' => '8'])->create()->load(['category', 'activeSkus.activeUnits.unit']);

    $visible = rendered(new PriceListRowResource($product, $product->activeSkus->first()?->activeUnits->first(), false), storeRequest());
    $hidden = rendered(new PriceListRowResource($product, $product->activeSkus->first()?->activeUnits->first(), true), storeRequest());

    expect(array_keys($visible))->toBe(['id', 'sku_id', 'variant', 'sku_unit_id', 'name', 'slug', 'kind', 'section', 'code', 'unit', 'conversion_factor', 'is_default', 'moq', 'cover_price', 'base_price', 'availability'])
        ->and($visible['kind'])->toBe('general')
        ->and($visible['section'])->toBe(['id' => $this->sectionId('general'), 'name' => 'عام', 'reference_price_label' => 'سعر القطاعي', 'icon' => 'package'])
        ->and($visible['availability'])->toBe('available')
        ->and($hidden)->not->toHaveKeys(['cover_price', 'base_price']);
});

test('BR-O14 a coming soon product shows its pre-order offer; the remaining quantity follows the cap and what is pre-ordered', function (): void {
    $product = Product::factory()->withSku(['conversion_factor' => 1])->preorderable(limit: 30, expectedAt: '2026-12-01')->create()->load('activeSkus');
    App\Modules\Orders\Models\Order::factory()->preorder()->withItems([['sku_id' => $product->activeSkus->first()->id, 'quantity' => 12, 'reserved' => false]])->create();

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
    $product = Product::factory()->withSku(['cover_price' => '120', 'base_price' => '90'])->create()->load(['activeSkus.activeUnits.tiers']);

    $own = rendered(new StoreProductResource($product, new Collection, false, new PricingContext(null, [$this->defaultUnitId($product->skus()->sole()) => '80.00'])), storeRequest())['sku'];
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
    $product->load(['activeSkus.activeUnits.tiers', 'relations.relatedProduct.activeSkus.activeUnits.tiers', 'incomingRelations']);

    $data = rendered(new StoreProductResource($product, new Collection, false, new PricingContext('10.00')), storeRequest());

    expect($data['related'][0]['product']['sku']['base_price'])->toBe('45.00')
        ->and($data['related'][0]['product']['sku']['price_source'])->toBe('group');
});

test('BR-PR14 a price list row shows the customer\'s wholesale price and its source', function (): void {
    $product = Product::factory()->withSku(['base_price' => '80'])->create()->load(['category', 'activeSkus.activeUnits.unit', 'activeSkus.activeUnits.tiers']);

    $row = rendered(new PriceListRowResource($product, $product->activeSkus->first()?->activeUnits->first(), false, new PricingContext('25.00')), storeRequest());
    $hidden = rendered(new PriceListRowResource($product, $product->activeSkus->first()?->activeUnits->first(), true, new PricingContext('25.00')), storeRequest());

    expect($row['base_price'])->toBe('60.00')
        ->and($row['price_source'])->toBe('group')
        ->and($hidden)->not->toHaveKeys(['base_price', 'price_source']);
});

function storePromotion(int $promotionId = 5): App\Modules\Pricing\Data\PromotionPrice
{
    return new App\Modules\Pricing\Data\PromotionPrice($promotionId, 'عرض الأضواء', '20.00', Carbon\CarbonImmutable::parse('2026-11-30 22:00:00'));
}

test('BR-PR15 a store sku priced by a promotion carries the promotion; prices hidden hide it', function (): void {
    $product = Product::factory()->withSku(['cover_price' => '120', 'base_price' => '90'])->create()->load(['activeSkus.activeUnits.tiers']);
    $context = new PricingContext(promotions: [$product->skus()->sole()->id => storePromotion()]);

    $sku = rendered(new StoreProductResource($product, new Collection, false, $context), storeRequest())['sku'];
    $hidden = rendered(new StoreProductResource($product, new Collection, true, $context), storeRequest())['sku'];

    expect($sku['base_price'])->toBe('72.00')
        ->and($sku['promotion'])->toBe(['id' => 5, 'name' => 'عرض الأضواء', 'discount_percent' => '20.00', 'ends_at' => '2026-11-30T22:00:00+00:00'])
        ->and($sku)->not->toHaveKey('price_source')
        ->and($hidden)->not->toHaveKeys(['base_price', 'promotion']);
});

test('BR-PR15 a store sku whose group price beats the promotion shows no promotion', function (): void {
    $product = Product::factory()->withSku(['base_price' => '90'])->create()->load(['activeSkus.activeUnits.tiers']);

    $sku = rendered(new StoreProductResource($product, new Collection, false, new PricingContext('30.00', [], [$product->skus()->sole()->id => storePromotion()])), storeRequest())['sku'];

    expect($sku['price_source'])->toBe('group')
        ->and($sku)->not->toHaveKey('promotion');
});

test('BR-PR15 a price list row priced by a promotion carries the promotion', function (): void {
    $product = Product::factory()->withSku(['base_price' => '80'])->create()->load(['category', 'activeSkus.activeUnits.unit', 'activeSkus.activeUnits.tiers']);

    $row = rendered(new PriceListRowResource($product, $product->activeSkus->first()?->activeUnits->first(), false, new PricingContext(promotions: [$product->skus()->sole()->id => storePromotion()])), storeRequest());

    expect($row['base_price'])->toBe('64.00')
        ->and($row['promotion']['id'])->toBe(5);
});

test('BR-PR15 the sku ids a page of products and its related products show', function (): void {
    $product = Product::factory()->withSku()->create();
    $related = Product::factory()->withSku()->create();
    $incoming = Product::factory()->withSku()->create();
    App\Modules\Catalog\Models\ProductRelation::factory()->create(['product_id' => $product->id, 'related_product_id' => $related->id]);
    App\Modules\Catalog\Models\ProductRelation::factory()->create(['product_id' => $incoming->id, 'related_product_id' => $product->id]);
    $product->load(['activeSkus', 'relations.relatedProduct.activeSkus', 'incomingRelations.product.activeSkus']);
    $bare = Product::factory()->create();

    expect(StoreProductResource::skuIds([$product, $bare]))->toBe([$product->activeSkus->first()->id, $related->skus->first()->id, $incoming->skus->first()->id])
        ->and(PriceListRowResource::skuIds(new Collection([$related->load('activeSkus'), $bare->load('activeSkus')])))->toBe([$related->skus->first()->id]);
});

test('D91 a sku sold by the piece and by the carton lists both units, each priced and stocked on its own', function (): void {
    $product = Product::factory()->withSku(['base_price' => '10.00', 'moq' => 6], onHand: 30)->create();
    $carton = $this->addUnit($product->skus()->sole(), ['conversion_factor' => 48, 'base_price' => '400.00', 'moq' => 1], [3 => '380.00']);
    $product->load(storeProductRelations());

    $sku = rendered(new StoreProductResource($product, new Collection, false, new PricingContext(null, [$carton->id => '390.00'])), storeRequest())['sku'];

    expect([$sku['base_price'], $sku['moq'], $sku['conversion_factor'], $sku['in_stock']])->toBe(['10.00', 6, 1, true])
        ->and(array_map(fn (array $unit): array => [$unit['id'], $unit['conversion_factor'], $unit['moq'], $unit['base_price'], $unit['tiers'], $unit['is_default'], $unit['in_stock']], $sku['units']))->toBe([
            [$this->defaultUnitId($product->skus()->sole()), 1, 6, '10.00', [], true, true],
            [$carton->id, 48, 1, '390.00', [], false, false],
        ])
        ->and($sku['units'][1]['price_source'])->toBe('customer');
});

test('D37 D91 a sku is in stock while its smallest unit can be ordered', function (): void {
    $product = Product::factory()->withSku(['conversion_factor' => 48], onHand: 12)->create();
    $this->addUnit($product->skus()->sole(), ['conversion_factor' => 12]);
    $product->load(storeProductRelations());

    $sku = rendered(new StoreProductResource($product, new Collection, false), storeRequest())['sku'];

    expect([$sku['in_stock'], $sku['units'][0]['in_stock'], $sku['units'][1]['in_stock']])->toBe([true, false, true]);
});

test('BR-P13 D91 the price list shows a row per unit, the default first', function (): void {
    $product = Product::factory()->withSku(['base_price' => '10.00'])->create();
    $carton = $this->addUnit($product->skus()->sole(), ['base_price' => '400.00']);
    $product->load(['category', 'activeSkus.activeUnits.unit', 'activeSkus.activeUnits.tiers']);

    $rows = PriceListRowResource::list(new Collection([$product]), false, false)->toResponse(storeRequest())->getData(true);

    expect(array_map(fn (array $row): array => [$row['id'], $row['sku_unit_id'], $row['base_price'], $row['is_default']], $rows['data']))->toBe([
        [$product->id, $this->defaultUnitId($product->skus()->sole()), '10.00', true],
        [$product->id, $carton->id, '400.00', false],
    ])
        ->and($rows['meta']['count'])->toBe(2);
});

/** @return list<string> */
function storeProductRelations(): array
{
    return App\Modules\Catalog\Queries\StoreProductListQuery::RELATIONS;
}

test('BR-P14 BR-PR14 the price range is at the shopper\'s group price', function (): void {
    $pen = $this->variantProduct(colours: ['أزرق', 'أحمر'], prices: ['أزرق' => '12.00', 'أحمر' => '8.00'])->load(storeProductRelations());

    $data = rendered(new StoreProductResource($pen, new Collection, false, new PricingContext('10.00')), storeRequest());

    expect($data['price_range'])->toBe(['min' => '7.20', 'max' => '10.80']);
});

test('BR-P14 an option value with an image shows it as the swatch', function (): void {
    $pen = $this->variantProduct(colours: ['أزرق', 'أحمر']);
    $image = ProductImage::factory()->for($pen)->create();
    $pen->options()->sole()->values()->where('value', 'أزرق')->update(['image_id' => $image->id]);
    $pen->load(storeProductRelations());

    $values = rendered(new StoreProductResource($pen, new Collection, false), storeRequest())['options'][0]['values'];

    expect($values[0]['image_url'])->toBe($image->url())
        ->and($values[1]['image_url'])->toBeNull();
});

test('BR-P14 BR-PR15 the sku ids of a variant product are its active variants', function (): void {
    $pen = $this->variantProduct(colours: ['أزرق', 'أسود', 'أحمر']);
    Sku::query()->where('code', 'PEN-2')->update(['status' => 'inactive']);
    $pen->load('activeSkus');

    expect(StoreProductResource::skuIds([$pen]))->toBe(Sku::query()->whereIn('code', ['PEN-1', 'PEN-3'])->orderBy('id')->pluck('id')->all());
});
