<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Customers\Models\CustomerSkuPrice;
use App\Modules\Pricing\Models\SkuPriceTier;

/*
| BR-PR12..PR14 (V2 Task 11, D89) on the storefront: the product list, the product page, the price list (page and file)
| and the cart show a logged-in customer their own prices. The book: cover 125.00, base 100.00, 10+ → 90.00, 100+ → 80.00.
| «موزع» is a 15% group (85.00); another customer has a negotiated 95.00 and no group.
*/

beforeEach(function (): void {
    [$this->product, $this->group] = $this->inTenant(function (): array {
        $product = Product::factory()->withSku(['code' => 'BK-1', 'cover_price' => '125', 'base_price' => '100', 'moq' => 1], onHand: 1000)->create(['name' => 'كتاب']);
        SkuPriceTier::factory()->create(['sku_id' => $product->sku()->sole()->id, 'min_quantity' => 10, 'unit_price' => '90.00']);
        SkuPriceTier::factory()->create(['sku_id' => $product->sku()->sole()->id, 'min_quantity' => 100, 'unit_price' => '80.00']);

        return [$product->refresh(), CustomerGroup::factory()->create(['name' => 'موزع', 'discount_percent' => '15.00'])];
    });
    $this->sku = $this->inTenant(fn (): Sku => $this->product->sku()->sole());
    $this->distributor = $this->makeCustomer(['customer_group_id' => $this->group->id]);
    $this->negotiated = $this->makeCustomer();
    $this->inTenant(fn () => CustomerSkuPrice::factory()->create(['customer_id' => $this->negotiated->id, 'sku_id' => $this->sku->id, 'unit_price' => '95.00']));
});

test('BR-PR14 a guest sees the base price and the tiers, without a price source', function (): void {
    $this->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertJsonPath('data.0.sku.base_price', '100.00')
        ->assertJsonPath('data.0.sku.discount_percent', '20.00')
        ->assertJsonPath('data.0.sku.tiers', [['min_quantity' => 10, 'unit_price' => '90.00'], ['min_quantity' => 100, 'unit_price' => '80.00']])
        ->assertJsonMissingPath('data.0.sku.price_source');
});

test('BR-PR12 a group customer sees the group price with only the tiers below it', function (): void {
    $response = $this->actingAs($this->distributor, 'customer')->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertJsonPath('data.0.sku.base_price', '85.00')
        ->assertJsonPath('data.0.sku.discount_percent', '32.00')
        ->assertJsonPath('data.0.sku.tiers', [['min_quantity' => 100, 'unit_price' => '80.00']])
        ->assertJsonPath('data.0.sku.price_source', 'group');

    expectDocumentedShape($response->json('data.0.sku'), 'StoreSku');
});

test('BR-PR13 a customer with a negotiated price sees it alone, even above the group or tier price', function (): void {
    $this->actingAs($this->negotiated, 'customer')->tenantJson('GET', "/api/store/products/{$this->product->slug}")
        ->assertOk()
        ->assertJsonPath('data.sku.base_price', '95.00')
        ->assertJsonPath('data.sku.tiers', [])
        ->assertJsonPath('data.sku.price_source', 'customer');
});

test('BR-PR14 a customer without a group or price sees what a guest sees', function (): void {
    $this->actingAs($this->makeCustomer(), 'customer')->tenantJson('GET', "/api/store/products/{$this->product->slug}")
        ->assertOk()
        ->assertJsonPath('data.sku.base_price', '100.00')
        ->assertJsonMissingPath('data.sku.price_source');
});

test('D89 an archived group stops lowering the customer\'s prices', function (): void {
    $this->inTenant(fn () => $this->group->forceFill(['status' => 'archived'])->save());

    $this->actingAs($this->distributor, 'customer')->tenantJson('GET', '/api/store/products')
        ->assertJsonPath('data.0.sku.base_price', '100.00')
        ->assertJsonMissingPath('data.0.sku.price_source');
});

test('BR-PR14 two customers see different prices on the price list', function (Closure $customer, string $price, string $source): void {
    $response = $this->actingAs($customer->call($this), 'customer')->tenantJson('GET', '/api/store/price-list')
        ->assertOk()
        ->assertJsonPath('data.0.base_price', $price)
        ->assertJsonPath('data.0.price_source', $source);

    expectDocumentedShape($response->json('data.0'), 'PriceListRow');
})->with([
    'group' => [fn (): Customer => $this->distributor, '85.00', 'group'],
    'negotiated' => [fn (): Customer => $this->negotiated, '95.00', 'customer'],
]);

test('BR-PR14 a guest sees the base price on the price list', function (): void {
    $this->tenantJson('GET', '/api/store/price-list')
        ->assertOk()
        ->assertJsonPath('data.0.base_price', '100.00')
        ->assertJsonMissingPath('data.0.price_source');
});

test('BR-PR14 the price list file has the customer\'s own wholesale price', function (): void {
    $response = $this->actingAs($this->distributor, 'customer')->tenantJson('GET', '/api/store/price-list/export')->assertOk();

    expect(spreadsheetRows(downloadedFile($response))[2][6])->toBe('85');
});

test('D1 prices hidden from guests stay hidden, with no price source', function (): void {
    $this->tenant->settings->update(['require_login_for_prices' => true]);

    $this->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertJsonPath('data.0.prices_hidden', true)
        ->assertJsonMissingPath('data.0.sku.base_price')
        ->assertJsonMissingPath('data.0.sku.price_source');
    $this->tenantJson('GET', '/api/store/price-list')->assertOk()->assertJsonMissingPath('data.0.price_source');
});

test('BR-PR14 the cart prices each customer at their own price, with its source', function (Closure $customer, int $quantity, string $unitPrice, string $source): void {
    $this->actingAs($customer->call($this), 'customer')
        ->tenantJson('POST', '/api/store/cart/items', ['sku_id' => $this->sku->id, 'quantity' => $quantity])
        ->assertOk()
        ->assertJsonPath('data.items.0.unit_price', $unitPrice)
        ->assertJsonPath('data.items.0.price_source', $source);
})->with([
    'group below base' => [fn (): Customer => $this->distributor, 1, '85.00', 'group'],
    'tier below group' => [fn (): Customer => $this->distributor, 100, '80.00', 'tier'],
    'negotiated' => [fn (): Customer => $this->negotiated, 100, '95.00', 'customer'],
]);
