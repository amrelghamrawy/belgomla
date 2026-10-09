<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\RemoveCartItem;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = Customer::factory()->create();
    $this->item = CartItem::factory()->for(Cart::factory()->for($this->customer))->create();
});

test('BR-CU06 the line leaves the cart and the rest is returned', function (): void {
    $cart = app(RemoveCartItem::class)->handle($this->customer, $this->item->sku_id);

    expect(CartItem::query()->count())->toBe(0)
        ->and($cart->lines)->toBe([]);
});

test('another customer cannot remove it', function (): void {
    expect(fn () => app(RemoveCartItem::class)->handle(Customer::factory()->create(), $this->item->sku_id))->toThrow(ModelNotFoundException::class);

    expect(CartItem::query()->count())->toBe(1);
});
