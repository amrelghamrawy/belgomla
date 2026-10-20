<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Actions\UpdateCartItem;
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
    $this->item = CartItem::factory()->for(Cart::factory()->for($this->customer))->create(['sku_id' => $this->sku->id, 'quantity' => 2]);
});

test('BR-CU06 the quantity changes and the cart is re-priced', function (): void {
    $cart = app(UpdateCartItem::class)->handle($this->customer, $this->sku->id, 4);

    expect($this->item->refresh()->quantity)->toBe(4)
        ->and($cart->itemsTotal)->toBe('40.00');
});

test('BR-INV01 a quantity above the stock is refused and the line kept', function (): void {
    expect(fn () => app(UpdateCartItem::class)->handle($this->customer, $this->sku->id, 51))->toThrow(CartLineInvalid::class);

    expect($this->item->refresh()->quantity)->toBe(2);
});

test('a sku that is not in this customer\'s cart is not found', function (): void {
    app(UpdateCartItem::class)->handle(Customer::factory()->create(), $this->sku->id, 3);
})->throws(ModelNotFoundException::class);

test('BR-O14 a pre-order line changes with the pre-order checks, without stock, the order line stays', function (): void {
    $sku = Product::factory()->withSku(['moq' => 2, 'base_price' => '10.00'])->preorderable()->create()->sku()->sole();
    $preorder = CartItem::factory()->preorder()->create(['cart_id' => $this->item->cart_id, 'sku_id' => $sku->id, 'quantity' => 2]);

    $cart = app(UpdateCartItem::class)->handle($this->customer, $sku->id, 80, CartLineKind::Preorder);

    expect($preorder->refresh()->quantity)->toBe(80)
        ->and($this->item->refresh()->quantity)->toBe(2)
        ->and($cart->preorderTotal)->toBe('800.00');
});

test('BR-O14 a sku without a pre-order line answers 404 for the pre-order kind', function (): void {
    app(UpdateCartItem::class)->handle($this->customer, $this->sku->id, 3, CartLineKind::Preorder);
})->throws(ModelNotFoundException::class);
