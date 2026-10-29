<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Models\OrderItem;

/*
| BR-P15 (V2 Task 15, D93): a bundle on the storefront, in the cart and at checkout. The bundle: one carton (20 pieces)
| of عربي (90.00) and two cartons of رياضيات (90.00) at the sum less 10 % (243.00); with 100 pieces of عربي and 400 of
| رياضيات, 5 bundles can be made.
*/

beforeEach(function (): void {
    $this->arabic = $this->orderableSku(['code' => 'AR-1'], onHand: 100, name: 'عربي');
    $this->maths = $this->orderableSku(['code' => 'MA-1'], name: 'رياضيات');
    $this->bundle = $this->gradeSet([$this->arabic->id => 1, $this->maths->id => 2], App\Modules\Pricing\Enums\BundlePriceMode::PercentOffSum, '10.00');
    $this->bundleSkuId = $this->bundleSku($this->bundle)->id;
});

test('BR-P15 the storefront shows a bundle with its components, availability and saving', function (): void {
    $response = $this->tenantJson('GET', '/api/store/products/'.urlencode($this->bundle->slug))
        ->assertOk()
        ->assertJsonPath('data.type', 'bundle')
        ->assertJsonPath('data.availability', 5)
        ->assertJsonPath('data.sku.base_price', '243.00')
        ->assertJsonPath('data.sku.available', 5)
        ->assertJsonPath('data.sku.in_stock', true)
        ->assertJsonPath('data.components_total', '270.00')
        ->assertJsonPath('data.saving', '27.00')
        ->assertJsonPath('data.components.*.product_name', ['عربي', 'رياضيات'])
        ->assertJsonPath('data.components.1.quantity', 2)
        ->assertJsonPath('data.components.0.in_stock', true);

    expect(array_keys($response->json('data.components.0')))->toBe(['product_name', 'slug', 'sku_code', 'label', 'unit', 'quantity', 'in_stock']);
    expectDocumentedShape(array_diff_key($response->json('data'), ['gallery' => true, 'related' => true]), 'StoreProduct');
});

test('D93 a simple product has a type and no bundle keys', function (): void {
    $this->tenantJson('GET', '/api/store/products?q='.urlencode('رياضيات'))
        ->assertOk()
        ->assertJsonPath('data.0.type', 'simple')
        ->assertJsonMissingPath('data.0.components')
        ->assertJsonMissingPath('data.0.availability')
        ->assertJsonMissingPath('data.0.saving');
});

test('D93 an unavailable bundle names the component that is missing', function (): void {
    $this->inTenant(fn () => SkuInventory::query()->whereKey($this->arabic->id)->update(['on_hand' => 10]));

    $this->tenantJson('GET', '/api/store/products/'.urlencode($this->bundle->slug))
        ->assertOk()
        ->assertJsonPath('data.availability', 0)
        ->assertJsonPath('data.sku.in_stock', false)
        ->assertJsonPath('data.components.*.in_stock', [false, true]);
});

test('BR-PR14 the saving is reckoned at the shopper\'s own prices, the group % on the bundle too', function (): void {
    $customer = $this->makeCustomer();
    $this->inTenant(fn () => $customer->forceFill(['customer_group_id' => CustomerGroup::factory()->create(['discount_percent' => '10.00'])->id])->save());
    $this->actingAsCustomer($customer);

    $this->tenantJson('GET', '/api/store/products/'.urlencode($this->bundle->slug))
        ->assertOk()
        ->assertJsonPath('data.sku.base_price', '218.70')
        ->assertJsonPath('data.components_total', '243.00')
        ->assertJsonPath('data.saving', '24.30');
});

test('D1 hidden prices hide the bundle sums too', function (): void {
    $this->tenant->settings->update(['require_login_for_prices' => true]);

    $this->tenantJson('GET', '/api/store/products/'.urlencode($this->bundle->slug))
        ->assertOk()
        ->assertJsonPath('data.availability', 5)
        ->assertJsonMissingPath('data.components_total')
        ->assertJsonMissingPath('data.saving');
});

test('BR-CU06 D93 a bundle goes in the cart up to what its components allow', function (): void {
    $this->actingAsCustomer($this->makeCustomer());

    $this->tenantJson('POST', '/api/store/cart/items', ['sku_id' => $this->bundleSkuId, 'quantity' => 5])
        ->assertSuccessful()
        ->assertJsonPath('data.items.0.is_bundle', true)
        ->assertJsonPath('data.items.0.available', 5)
        ->assertJsonPath('data.items.0.line_total', '1215.00');
});

test('BR-INV01 D93 more bundles than the components allow are refused in the cart', function (): void {
    $this->actingAsCustomer($this->makeCustomer());

    $this->tenantJson('POST', '/api/store/cart/items', ['sku_id' => $this->bundleSkuId, 'quantity' => 6])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'cart_line_invalid')
        ->assertJsonPath('error.items.0.reason', 'insufficient_stock')
        ->assertJsonPath('error.items.0.available', 5);
});

test('D93 a bundle whose component is withdrawn cannot go in the cart', function (): void {
    $this->inTenant(fn () => Product::query()->whereKey($this->maths->product_id)->update(['status' => ProductStatus::Inactive]));
    $this->actingAsCustomer($this->makeCustomer());

    $this->tenantJson('POST', '/api/store/cart/items', ['sku_id' => $this->bundleSkuId, 'quantity' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('error.items.0.reason', 'product_inactive');
});

test('BR-PR06 D93 checking out a bundle reserves its components and snapshots the bundle with its children', function (): void {
    $customer = $this->actingAsCustomer($this->makeCustomer());
    $address = $this->addressOf($customer);
    $this->tenantJson('POST', '/api/store/cart/items', ['sku_id' => $this->bundleSkuId, 'quantity' => 2])->assertSuccessful();

    $response = $this->tenantJson('POST', '/api/store/checkout', ['address_id' => $address->id])->assertCreated();

    $order = $this->tenantJson('GET', '/api/store/orders/'.$response->json('data.order.number'))->assertOk();

    expect($order->json('data.items_total'))->toBe('486.00')
        ->and(array_map(fn (array $item): array => [$item['sku_code'], $item['is_bundle'], $item['parent_item_id'] !== null, $item['quantity']], $order->json('data.items')))->toBe([
            ['SET-1', true, false, 2],
            ['AR-1', false, true, 2],
            ['MA-1', false, true, 4],
        ])
        ->and($this->inTenant(fn () => SkuInventory::query()->whereKey([$this->arabic->id, $this->maths->id])->orderBy('sku_id')->pluck('reserved')->all()))->toBe([40, 80])
        ->and($this->inTenant(fn () => OrderItem::query()->where('is_bundle', true)->count()))->toBe(1);
});
