<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Actions\AddCartItem;
use App\Modules\Customers\Data\CartLineData;
use App\Modules\Customers\Exceptions\CartLineInvalid;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = Customer::factory()->create();
    $this->sku = Product::factory()->withSku(['moq' => 2, 'base_price' => '10.00'], onHand: 50)->create()->sku()->sole();
});

test('BR-CU06 the first add creates the customer cart and the line, and returns the priced cart', function (): void {
    $cart = app(AddCartItem::class)->handle($this->customer, new CartLineData($this->sku->id, 3));

    expect(Cart::query()->sole()->customer_id)->toBe($this->customer->id)
        ->and(CartItem::query()->sole()->only(['sku_id', 'quantity']))->toBe(['sku_id' => $this->sku->id, 'quantity' => 3])
        ->and($cart->itemsTotal)->toBe('30.00');
});

test('BR-CU06 adding again replaces the quantity', function (): void {
    app(AddCartItem::class)->handle($this->customer, new CartLineData($this->sku->id, 3));

    app(AddCartItem::class)->handle($this->customer, new CartLineData($this->sku->id, 5));

    expect(CartItem::query()->sole()->quantity)->toBe(5);
});

test('a line that cannot be ordered is not written', function (): void {
    expect(fn () => app(AddCartItem::class)->handle($this->customer, new CartLineData($this->sku->id, 1)))->toThrow(CartLineInvalid::class);

    expect(CartItem::query()->count())->toBe(0)
        ->and(Cart::query()->count())->toBe(0);
});

test('BR-T04 an unknown sku is not found', function (): void {
    app(AddCartItem::class)->handle($this->customer, new CartLineData($this->sku->id + 1000, 3));
})->throws(ModelNotFoundException::class);

test('BR-P04 an inactive product is refused', function (): void {
    Product::query()->update(['status' => ProductStatus::Inactive]);

    app(AddCartItem::class)->handle($this->customer, new CartLineData($this->sku->id, 3));
})->throws(CartLineInvalid::class, 'المنتج غير متاح للطلب');

test('another sku goes on its own line', function (): void {
    $other = Sku::factory()->withStock(50)->create();

    app(AddCartItem::class)->handle($this->customer, new CartLineData($this->sku->id, 3));
    $cart = app(AddCartItem::class)->handle($this->customer, new CartLineData($other->id, 1));

    expect($cart->lines)->toHaveCount(2);
});
