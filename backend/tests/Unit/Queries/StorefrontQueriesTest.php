<?php

declare(strict_types=1);

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Queries\PriceListQuery;
use App\Modules\Catalog\Queries\PriceVisibilityQuery;
use App\Modules\Catalog\Queries\StorefrontFieldsQuery;
use App\Modules\Catalog\Queries\StoreProductDetailQuery;
use App\Modules\Catalog\Queries\StoreProductListQuery;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\User;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('D1 the tenant setting says whether prices need a login', function (): void {
    expect(app(CurrentTenantSettingsQuery::class)->requiresLoginForPrices())->toBeFalse();

    $this->tenant->settings->update(['require_login_for_prices' => true]);

    expect(app(CurrentTenantSettingsQuery::class)->requiresLoginForPrices())->toBeTrue();
});

test('D1 prices are hidden only from guests of a tenant that requires login', function (bool $setting, bool $customer, bool $hidden): void {
    $this->tenant->settings->update(['require_login_for_prices' => $setting]);

    expect(app(PriceVisibilityQuery::class)->hiddenFor($customer ? new User : null))->toBe($hidden);
})->with([
    'public prices, guest' => [false, false, false],
    'public prices, customer' => [false, true, false],
    'login required, guest' => [true, false, true],
    'login required, customer' => [true, true, false],
]);

test('storefront fields are the active, shown fields in sort order, keyed by key', function (): void {
    ProductFieldDefinition::factory()->create(['key' => 'b', 'sort_order' => 2]);
    ProductFieldDefinition::factory()->create(['key' => 'a', 'sort_order' => 1]);
    ProductFieldDefinition::factory()->hidden()->create(['key' => 'internal']);
    ProductFieldDefinition::factory()->archived()->create(['key' => 'old']);

    $fields = app(StorefrontFieldsQuery::class)->get();

    expect($fields->keys()->all())->toBe(['a', 'b'])
        ->and($fields->first()->relationLoaded('options'))->toBeTrue();
});

test('the store list eager-loads what the resource needs and hides inactive products', function (): void {
    $visible = Product::factory()->withSku()->create();
    Product::factory()->withSku()->inactive()->create();

    $page = app(StoreProductListQuery::class)->paginate(new ProductFilterData, null, 24);

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->is($visible))->toBeTrue()
        ->and($page->items()[0]->relationLoaded('activeSkus'))->toBeTrue()
        ->and($page->items()[0]->relationLoaded('mainImage'))->toBeTrue();
});

test('the detail query finds a visible product by slug and refuses an inactive one', function (): void {
    $product = Product::factory()->create(['name' => 'قلم']);
    $inactive = Product::factory()->inactive()->create(['name' => 'ممحاة']);

    expect(app(StoreProductDetailQuery::class)->bySlug('قلم')->is($product))->toBeTrue()
        ->and(fn () => app(StoreProductDetailQuery::class)->bySlug($inactive->slug))->toThrow(ModelNotFoundException::class);
});

test('BR-P13 the price list reads one row more than the limit to detect truncation', function (): void {
    config(['catalog.price_list_limit' => 2]);
    Product::factory()->count(2)->create();

    $exact = app(PriceListQuery::class)->get(new ProductFilterData);
    Product::factory()->create();
    $over = app(PriceListQuery::class)->get(new ProductFilterData);

    expect($exact['truncated'])->toBeFalse()
        ->and($exact['rows'])->toHaveCount(2)
        ->and($over['truncated'])->toBeTrue()
        ->and($over['rows'])->toHaveCount(2);
});

test('BR-P14 the store list loads the variants with their option values and the options with their swatches', function (): void {
    $this->variantProduct();

    $product = app(StoreProductListQuery::class)->paginate(new ProductFilterData, null, 24)->items()[0];

    expect($product->activeSkus)->toHaveCount(3)
        ->and($product->activeSkus->first()->relationLoaded('optionValues'))->toBeTrue()
        ->and($product->options->first()->values->first()->relationLoaded('image'))->toBeTrue();
});

test('BR-P14 BR-PR15 on offer ignores a promotion that covers only an inactive variant', function (): void {
    $this->variantProduct(colours: ['أزرق', 'أسود']);
    $inactive = Sku::query()->where('code', 'PEN-2')->sole();
    Promotion::factory()->covering($inactive)->create();
    $inactive->update(['status' => 'inactive']);

    $offers = app(StoreProductListQuery::class)->paginate(new ProductFilterData, null, 24, onOffer: true);

    expect($offers->total())->toBe(0);
});

test('BR-P14 BR-PR15 on offer keeps a product with a promoted active variant', function (): void {
    $pen = $this->variantProduct(colours: ['أزرق', 'أسود']);
    Promotion::factory()->covering(Sku::query()->where('code', 'PEN-2')->sole())->create();

    $offers = app(StoreProductListQuery::class)->paginate(new ProductFilterData, null, 24, onOffer: true);

    expect($offers->total())->toBe(1)
        ->and($offers->items()[0]->is($pen))->toBeTrue();
});
