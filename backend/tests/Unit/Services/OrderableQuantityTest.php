<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Exceptions\CartLineInvalid;
use App\Modules\Customers\Services\OrderableQuantity;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

function orderableSku(ProductStatus $status = ProductStatus::Active, ?int $onHand = 100): Sku
{
    $product = Product::factory()->state(['status' => $status])->withSku(['moq' => 5, 'conversion_factor' => 10], onHand: $onHand)->create();

    return app(CartSkuQuery::class)->find($product->sku()->sole()->id);
}

test('BR-MOQ01 BR-INV01 a quantity between the moq and the stock is orderable', function (int $quantity): void {
    expect(fn () => app(OrderableQuantity::class)->assert(orderableSku(), $quantity))->not->toThrow(CartLineInvalid::class);
})->with(['exactly the moq' => [5], 'exactly the stock (10 × 10 = 100 base units)' => [10]]);

test('BR-MOQ01 one below the moq is refused', function (): void {
    expect(fn () => app(OrderableQuantity::class)->assert(orderableSku(), 4))
        ->toThrow(function (CartLineInvalid $e): void {
            expect($e->items[0]['reason'] ?? null)->toBe('below_moq')
                ->and($e->items[0]['moq'] ?? null)->toBe(5);
        });
});

test('BR-INV01 BR-U03 one selling unit above the stock is refused, in base units', function (): void {
    expect(fn () => app(OrderableQuantity::class)->assert(orderableSku(onHand: 105), 11))
        ->toThrow(function (CartLineInvalid $e): void {
            expect($e->items[0]['reason'] ?? null)->toBe('insufficient_stock')
                ->and($e->items[0]['available'] ?? null)->toBe(10);
        });
});

test('BR-INV01 a sku without a stock row has nothing to order', function (): void {
    expect(fn () => app(OrderableQuantity::class)->assert(orderableSku(onHand: null), 5))
        ->toThrow(CartLineInvalid::class, 'الكمية غير متوفرة');
});

test('BR-P04 D21 only active products are orderable', function (ProductStatus $status): void {
    expect(fn () => app(OrderableQuantity::class)->assert(orderableSku($status), 5))
        ->toThrow(CartLineInvalid::class, 'المنتج غير متاح للطلب');
})->with(['inactive' => [ProductStatus::Inactive], 'coming soon' => [ProductStatus::ComingSoon]]);

test('BR-O14 a pre-order line needs a coming soon product with pre-orders on and the MOQ, never stock', function (): void {
    $sku = orderableSku(ProductStatus::ComingSoon, onHand: null);
    Product::query()->whereKey($sku->product_id)->update(['preorder_enabled' => true]);
    $sku = app(CartSkuQuery::class)->find($sku->id);

    app(OrderableQuantity::class)->assert($sku, 500, CartLineKind::Preorder);

    expect(app(OrderableQuantity::class)->preorderable($sku))->toBeTrue()
        ->and(fn () => app(OrderableQuantity::class)->assert($sku, 4, CartLineKind::Preorder))->toThrow(function (CartLineInvalid $e) use ($sku): void {
            expect($e->items)->toBe([['sku_id' => $sku->id, 'reason' => 'below_moq', 'moq' => 5]]);
        });
});

test('BR-O14 a product that does not take pre-orders cannot be pre-ordered', function (ProductStatus $status, bool $enabled): void {
    $sku = orderableSku($status);
    Product::query()->whereKey($sku->product_id)->update(['preorder_enabled' => $enabled]);
    $sku = app(CartSkuQuery::class)->find($sku->id);

    expect(app(OrderableQuantity::class)->preorderable($sku))->toBeFalse()
        ->and(fn () => app(OrderableQuantity::class)->assert($sku, 5, CartLineKind::Preorder))->toThrow(function (CartLineInvalid $e) use ($sku): void {
            expect($e->items)->toBe([['sku_id' => $sku->id, 'reason' => 'preorder_unavailable']]);
        });
})->with([
    'coming soon, pre-orders off' => [ProductStatus::ComingSoon, false],
    'active' => [ProductStatus::Active, true],
    'inactive' => [ProductStatus::Inactive, true],
]);
