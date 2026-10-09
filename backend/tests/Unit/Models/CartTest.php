<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\Scopes\InCartOf;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('cart to array', function (): void {
    expect(array_keys(Cart::factory()->create()->fresh()?->toArray() ?? []))->toBe(['id', 'tenant_id', 'customer_id', 'created_at', 'updated_at']);
});

test('cart item to array', function (): void {
    expect(array_keys(CartItem::factory()->create()->fresh()?->toArray() ?? []))->toBe(['id', 'tenant_id', 'cart_id', 'sku_id', 'quantity', 'created_at', 'updated_at']);
});

test('BR-CU06 a customer has at most one cart', function (): void {
    $customer = Customer::factory()->create();
    Cart::factory()->for($customer)->create();

    Cart::factory()->for($customer)->create();
})->throws(QueryException::class, 'carts_customer_id_unique');

test('BR-CU06 a sku appears once per cart', function (): void {
    $item = CartItem::factory()->create();

    CartItem::factory()->create(['cart_id' => $item->cart_id, 'sku_id' => $item->sku_id]);
})->throws(QueryException::class, 'cart_items_cart_id_sku_id_unique');

test('BR-U05 a cart quantity is at least 1', function (): void {
    CartItem::factory()->create(['quantity' => 0]);
})->throws(QueryException::class, 'cart_items_quantity_positive');

test('relations: the cart, its customer and its lines in insertion order; a line and its sku', function (): void {
    $cart = Cart::factory()->create();
    $first = CartItem::factory()->for($cart)->create();
    $second = CartItem::factory()->for($cart)->create();

    expect($cart->customer)->toBeInstanceOf(Customer::class)
        ->and($cart->items->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($first->cart->is($cart))->toBeTrue()
        ->and($first->sku)->toBeInstanceOf(Sku::class)
        ->and($first->quantity)->toBe(1);
});

test('InCartOf keeps only the lines of one customer\'s cart', function (): void {
    $mine = CartItem::factory()->create();
    CartItem::factory()->create();

    expect(CartItem::query()->tap(new InCartOf($mine->cart->customer))->pluck('id')->all())->toBe([$mine->id]);
});

test('deleting a cart deletes its lines', function (): void {
    $item = CartItem::factory()->create();

    $item->cart->delete();

    expect(CartItem::query()->count())->toBe(0);
});
