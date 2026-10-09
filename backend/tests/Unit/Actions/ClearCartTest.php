<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\ClearCart;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-CU06 clearing empties only this customer\'s cart and keeps the cart row', function (): void {
    $customer = Customer::factory()->create();
    $cart = Cart::factory()->for($customer)->create();
    CartItem::factory()->for($cart)->count(2)->create();
    $theirs = CartItem::factory()->create();

    app(ClearCart::class)->handle($customer);

    expect(CartItem::query()->pluck('id')->all())->toBe([$theirs->id])
        ->and($cart->exists)->toBeTrue()
        ->and(Cart::query()->whereKey($cart->id)->exists())->toBeTrue();
});

test('clearing a customer without a cart does nothing', function (): void {
    app(ClearCart::class)->handle(Customer::factory()->create());

    expect(Cart::query()->count())->toBe(0);
});
