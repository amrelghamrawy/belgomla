<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Actions\AddCartItem;
use App\Modules\Customers\Data\CartLineData;
use App\Modules\Customers\Enums\CartLineKind;
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

test('BR-O14 a coming soon product with pre-orders on is added as a pre-order line without any stock', function (): void {
    $sku = Product::factory()->withSku(['moq' => 2, 'base_price' => '10.00'], onHand: 0)->preorderable()->create()->sku()->sole();

    $cart = app(AddCartItem::class)->handle($this->customer, new CartLineData($sku->id, 40, CartLineKind::Preorder));

    expect(CartItem::query()->sole()->only(['sku_id', 'quantity', 'kind']))->toBe(['sku_id' => $sku->id, 'quantity' => 40, 'kind' => CartLineKind::Preorder])
        ->and($cart->lines)->toBe([])
        ->and($cart->preorderTotal)->toBe('400.00');
});

test('BR-O14 order lines and pre-order lines sit in the cart side by side, priced apart', function (): void {
    $preorderable = Product::factory()->withSku(['moq' => 2, 'base_price' => '15.00'])->preorderable()->create()->sku()->sole();
    app(AddCartItem::class)->handle($this->customer, new CartLineData($this->sku->id, 3));

    $cart = app(AddCartItem::class)->handle($this->customer, new CartLineData($preorderable->id, 7, CartLineKind::Preorder));

    expect(CartItem::query()->orderBy('id')->get()->map(fn (CartItem $item): array => [$item->sku_id, $item->kind, $item->quantity])->all())
        ->toBe([[$this->sku->id, CartLineKind::Order, 3], [$preorderable->id, CartLineKind::Preorder, 7]])
        ->and($cart->itemsTotal)->toBe('30.00')
        ->and($cart->preorderTotal)->toBe('105.00');
});

test('BR-O14 only a coming soon product with pre-orders on can be pre-ordered', function (array $state): void {
    Product::query()->whereKey($this->sku->product_id)->update($state);

    expect(fn () => app(AddCartItem::class)->handle($this->customer, new CartLineData($this->sku->id, 3, CartLineKind::Preorder)))
        ->toThrow(function (CartLineInvalid $e): void {
            expect($e->items)->toBe([['sku_id' => $this->sku->id, 'reason' => 'preorder_unavailable']]);
        })
        ->and(CartItem::query()->count())->toBe(0);
})->with([
    'an active product' => [['status' => 'active']],
    'coming soon without pre-orders' => [['status' => 'coming_soon', 'preorder_enabled' => false]],
    'an inactive product' => [['status' => 'inactive', 'preorder_enabled' => true]],
]);

test('BR-MOQ01 a pre-order line must reach the MOQ', function (): void {
    Product::query()->whereKey($this->sku->product_id)->update(['status' => 'coming_soon', 'preorder_enabled' => true]);

    expect(fn () => app(AddCartItem::class)->handle($this->customer, new CartLineData($this->sku->id, 1, CartLineKind::Preorder)))
        ->toThrow(function (CartLineInvalid $e): void {
            expect($e->items)->toBe([['sku_id' => $this->sku->id, 'reason' => 'below_moq', 'moq' => 2]]);
        });
});
