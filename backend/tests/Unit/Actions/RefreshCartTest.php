<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Actions\RefreshCart;
use App\Modules\Customers\Data\PricedCartLine;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = Customer::factory()->create();
    $this->cart = Cart::factory()->for($this->customer)->create();
});

function cartLine(Cart $cart, string $name, ProductStatus $status = ProductStatus::Active): CartItem
{
    $sku = Product::factory()->state(['name' => $name, 'status' => $status])->withSku(['base_price' => '10.00'], onHand: 100)->create()->sku()->sole();

    return CartItem::factory()->for($cart)->create(['sku_id' => $sku->id, 'quantity' => 2]);
}

test('BR-P04 lines of inactive products are deleted and reported, the rest priced', function (): void {
    $kept = cartLine($this->cart, 'متاح');
    $gone = cartLine($this->cart, 'متوقف', ProductStatus::Inactive);

    $cart = app(RefreshCart::class)->handle($this->customer);

    expect(array_map(fn (PricedCartLine $line): int => $line->skuId, $cart->lines))->toBe([$kept->sku_id])
        ->and($cart->removed)->toBe([['product_name' => 'متوقف', 'reason' => 'inactive']])
        ->and($cart->itemsTotal)->toBe('20.00')
        ->and(CartItem::query()->whereKey($gone->id)->exists())->toBeFalse();
});

test('a cart with only active lines deletes nothing', function (): void {
    cartLine($this->cart, 'متاح');

    expect(app(RefreshCart::class)->handle($this->customer)->removed)->toBe([])
        ->and(CartItem::query()->count())->toBe(1);
});

test('a customer without a cart gets an empty one', function (): void {
    $cart = app(RefreshCart::class)->handle(Customer::factory()->create());

    expect([$cart->lines, $cart->removed, $cart->itemsTotal])->toBe([[], [], '0.00']);
});
