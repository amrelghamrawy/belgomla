<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Tenancy\Models\TenantSetting;

/*
| D1 / BR-CU04 with the customer guard in place (tasks/5-customers.md §2 "Store price visibility"): with
| `require_login_for_prices` on, guests see no prices and cannot add to the cart; a logged-in customer does.
*/

beforeEach(function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['require_login_for_prices' => true]);
    $this->product = $this->inTenant(fn () => Product::factory()->withSku(['base_price' => '90.00'], onHand: 100)->create());
});

test('D1 a logged-in customer sees prices on the product list', function (): void {
    $this->actingAsCustomer();

    $this->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertJsonPath('data.0.sku.base_price', '90.00')
        ->assertJsonMissingPath('data.0.prices_hidden');
});

test('D1 a guest sees no prices on the product list', function (): void {
    $this->tenantJson('GET', '/api/store/products')
        ->assertOk()
        ->assertJsonPath('data.0.prices_hidden', true)
        ->assertJsonMissingPath('data.0.sku.base_price');
});

test('D1 a logged-in customer sees prices on the price list', function (): void {
    $this->actingAsCustomer();

    $this->tenantJson('GET', '/api/store/price-list')
        ->assertOk()
        ->assertJsonPath('meta.prices_hidden', false)
        ->assertJsonPath('data.0.base_price', '90.00');
});

test('D51 a blocked customer still browses the store read-only', function (): void {
    $this->actingAsCustomer($this->makeCustomer(['status' => CustomerStatus::Blocked]));

    $this->tenantJson('GET', '/api/store/products')->assertOk();
    $this->tenantJson('GET', '/api/store/cart')->assertForbidden();
});

test('D53 a guest adding to the cart while prices are hidden is told to log in', function (): void {
    $this->tenantJson('POST', '/api/store/cart/items', ['sku_id' => $this->inTenant(fn () => $this->product->sku()->sole()->id), 'quantity' => 1])
        ->assertForbidden()
        ->assertExactJson(['error' => ['code' => 'prices_hidden', 'message' => 'سجّل الدخول لعرض الأسعار والإضافة إلى السلة']]);
});

test('BR-CU06 D53 a guest adding to the cart with public prices must log in', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['require_login_for_prices' => false]);

    $this->tenantJson('POST', '/api/store/cart/items', ['sku_id' => 1, 'quantity' => 1])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});
