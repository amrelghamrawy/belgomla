<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\CustomerInvitation;
use App\Modules\Tenancy\Models\TenantSetting;
use Illuminate\Support\Facades\Queue;

/*
| The storefront part of the OpenAPI spec (tasks/9) checked against real responses: each object has every
| required key of its schema and nothing undocumented, so the generated frontend types cannot drift.
*/

test('categories match the Category schema, children included', function (): void {
    $this->inTenant(fn () => Category::factory()->childOf(Category::factory()->create())->create());

    $data = $this->tenantJson('GET', '/api/store/categories')->assertOk()->json('data');

    expectDocumentedShape($data[0], 'Category');
    expectDocumentedShape($data[0]['children'][0], 'Category');
});

test('filters match the Facet and FacetOption schemas', function (): void {
    $this->inTenant(function (): void {
        $grade = ProductFieldDefinition::factory()->select()->filterable()->create(['key' => 'grade']);
        $first = ProductFieldOption::factory()->for($grade, 'definition')->create();
        Product::factory()->create(['custom_fields' => ['grade' => $first->id]]);
    });

    $data = $this->tenantJson('GET', '/api/store/filters')->assertOk()->json('data');

    expectDocumentedShape($data[0], 'Facet');
    expectDocumentedShape($data[0]['options'][0], 'FacetOption');
});

test('the product list matches StoreProduct, StoreSku and the pagination schemas', function (): void {
    $this->orderableSku(['cover_price' => '120.00']);

    $response = $this->tenantJson('GET', '/api/store/products')->assertOk();

    expectDocumentedShape($response->json('data.0'), 'StoreProduct');
    expectDocumentedShape($response->json('data.0.sku'), 'StoreSku');
    expectDocumentedShape($response->json('data.0.sku.tiers.0'), 'PriceTier');
    expectDocumentedShape($response->json('links'), 'PaginationLinks');
    expectDocumentedShape($response->json('meta'), 'PaginationMeta');
});

test('a product with hidden prices and a coming-soon product still match the schemas', function (): void {
    $this->inTenant(fn () => TenantSetting::query()->firstOrFail()->update(['require_login_for_prices' => true]));
    $this->orderableSku(status: ProductStatus::ComingSoon);

    $data = $this->tenantJson('GET', '/api/store/products')->assertOk()->json('data.0');

    expect($data['prices_hidden'])->toBeTrue()
        ->and($data['sku']['coming_soon'])->toBeTrue();
    expectDocumentedShape($data, 'StoreProduct');
    expectDocumentedShape($data['sku'], 'StoreSku');
});

test('the product page matches StoreProductDetail, ProductImage and RelatedProduct', function (): void {
    $sku = $this->orderableSku();
    $book = $this->inTenant(fn (): Product => $sku->product);
    $this->inTenant(function () use ($book): void {
        $revision = Product::factory()->withSku(onHand: 10)->create();
        ProductRelation::factory()->revisionOf()->create(['product_id' => $revision->id, 'related_product_id' => $book->id]);
        ProductImage::factory()->for($book)->main()->create();
    });

    $data = $this->tenantJson('GET', '/api/store/products/'.urlencode($book->slug))->assertOk()->json('data');

    expectDocumentedShape($data, 'StoreProductDetail');
    expectDocumentedShape($data['gallery'][0], 'ProductImage');
    expectDocumentedShape($data['related'][0], 'RelatedProduct');
    expectDocumentedShape($data['related'][0]['product'], 'StoreProduct');
});

test('the price list matches PriceListRow and PriceListMeta', function (): void {
    $this->orderableSku();

    $response = $this->tenantJson('GET', '/api/store/price-list')->assertOk();

    expectDocumentedShape($response->json('data.0'), 'PriceListRow');
    expectDocumentedShape($response->json('meta'), 'PriceListMeta');
});

test('register answers with the OtpSent schema', function (): void {
    Queue::fake();

    $data = $this->tenantJson('POST', '/api/store/auth/register', ['name' => 'محمود', 'phone' => '01012345678', 'password' => 'secret123'])
        ->assertOk()
        ->json('data');

    expectDocumentedShape($data, 'OtpSent');
});

test('the invitation page matches the Invitation schema', function (): void {
    $customer = $this->makeCustomer(['status' => CustomerStatus::Invited, 'password' => null, 'phone_verified_at' => null]);
    $this->inTenant(fn () => CustomerInvitation::factory()->token(str_repeat('a', 40))->create(['customer_id' => $customer->id]));

    $data = $this->tenantJson('GET', '/api/store/auth/invitation/'.str_repeat('a', 40))->assertOk()->json('data');

    expectDocumentedShape($data, 'Invitation');
});

test('addresses match the Address schema', function (): void {
    $this->addressOf($this->actingAsCustomer());

    $data = $this->tenantJson('GET', '/api/store/addresses')->assertOk()->json('data');

    expectDocumentedShape($data[0], 'Address');
});

test('the cart matches Cart, CartItem and RemovedCartItem', function (): void {
    $customer = $this->actingAsCustomer();
    $active = $this->orderableSku();
    $gone = $this->orderableSku(status: ProductStatus::Inactive, name: 'قديم');
    $this->fillCart($customer, [$active->id => 6, $gone->id => 6]);

    $data = $this->tenantJson('GET', '/api/store/cart')->assertOk()->json('data');

    expectDocumentedShape($data, 'Cart');
    expectDocumentedShape($data['items'][0], 'CartItem');
    expectDocumentedShape($data['removed_items'][0], 'RemovedCartItem');
});

test('a cart line problem matches the LineProblem schema', function (): void {
    $this->actingAsCustomer();
    $sku = $this->orderableSku();

    $items = $this->tenantJson('POST', '/api/store/cart/items', ['sku_id' => $sku->id, 'quantity' => 1])
        ->assertUnprocessable()
        ->json('error.items');

    expectDocumentedShape($items[0], 'LineProblem');
});

test('checkout answers with the order in the OrderDetail schema', function (): void {
    $customer = $this->actingAsCustomer();
    $address = $this->addressOf($customer);
    $this->fillCart($customer, [$this->orderableSku()->id => 6]);

    $data = $this->tenantJson('POST', '/api/store/checkout', ['address_id' => $address->id])->assertCreated()->json('data');

    expect(array_keys($data))->toBe(['order']);
    expectDocumentedShape($data['order'], 'OrderDetail');
});

test('the order list matches Order exactly, without items and history', function (): void {
    $customer = $this->actingAsCustomer();
    $this->placeOrder($customer, [$this->orderableSku()->id => 6]);

    $data = $this->tenantJson('GET', '/api/store/orders')->assertOk()->json('data.0');

    expect(array_keys($data))->toBe(openApiSchema('Order')['required']);
});

test('the order page matches OrderDetail, OrderItem and OrderHistoryEntry', function (): void {
    $customer = $this->actingAsCustomer();
    $order = $this->placeOrder($customer, [$this->orderableSku()->id => 6]);

    $data = $this->tenantJson('GET', "/api/store/orders/{$order->number}")->assertOk()->json('data');

    expectDocumentedShape($data, 'OrderDetail');
    expectDocumentedShape($data['items'][0], 'OrderItem');
    expectDocumentedShape($data['history'][0], 'OrderHistoryEntry');
});
