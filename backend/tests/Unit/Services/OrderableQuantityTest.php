<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Exceptions\CartLineInvalid;
use App\Modules\Customers\Services\OrderableQuantity;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

/** The default unit (10 base units, MOQ 5) of a new SKU, loaded as a cart line reads it. */
function orderableUnit(ProductStatus $status = ProductStatus::Active, ?int $onHand = 100): SkuUnit
{
    $product = Product::factory()->state(['status' => $status])->withSku(['moq' => 5, 'conversion_factor' => 10], onHand: $onHand)->create();

    return app(CartSkuQuery::class)->findDefault($product->sku()->sole()->id);
}

test('BR-MOQ01 BR-INV01 a quantity between the moq and the stock is orderable', function (int $quantity): void {
    expect(fn () => app(OrderableQuantity::class)->assert(orderableUnit(), $quantity))->not->toThrow(CartLineInvalid::class);
})->with(['exactly the moq' => [5], 'exactly the stock (10 × 10 = 100 base units)' => [10]]);

test('BR-MOQ01 one below the moq is refused', function (): void {
    expect(fn () => app(OrderableQuantity::class)->assert(orderableUnit(), 4))
        ->toThrow(function (CartLineInvalid $e): void {
            expect($e->items[0]['reason'] ?? null)->toBe('below_moq')
                ->and($e->items[0]['moq'] ?? null)->toBe(5);
        });
});

test('BR-INV01 BR-U03 one selling unit above the stock is refused, in base units', function (): void {
    expect(fn () => app(OrderableQuantity::class)->assert(orderableUnit(onHand: 105), 11))
        ->toThrow(function (CartLineInvalid $e): void {
            expect($e->items[0]['reason'] ?? null)->toBe('insufficient_stock')
                ->and($e->items[0]['available'] ?? null)->toBe(10);
        });
});

test('BR-INV01 a sku without a stock row has nothing to order', function (): void {
    expect(fn () => app(OrderableQuantity::class)->assert(orderableUnit(onHand: null), 5))
        ->toThrow(CartLineInvalid::class, 'الكمية غير متوفرة');
});

test('BR-P04 D21 only active products are orderable', function (ProductStatus $status): void {
    expect(fn () => app(OrderableQuantity::class)->assert(orderableUnit($status), 5))
        ->toThrow(CartLineInvalid::class, 'المنتج غير متاح للطلب');
})->with(['inactive' => [ProductStatus::Inactive], 'coming soon' => [ProductStatus::ComingSoon]]);

test('D91 BR-U03 what the cart already holds of the SKU in its other units counts against the stock', function (): void {
    $unit = orderableUnit(onHand: 100);

    expect(fn () => app(OrderableQuantity::class)->assert($unit, 6, heldBase: 40))->not->toThrow(CartLineInvalid::class)
        ->and(fn () => app(OrderableQuantity::class)->assert($unit, 7, heldBase: 40))->toThrow(function (CartLineInvalid $e) use ($unit): void {
            expect($e->items)->toBe([['sku_id' => $unit->sku_id, 'sku_unit_id' => $unit->id, 'reason' => 'insufficient_stock', 'available' => 6]]);
        });
});

test('D91 a unit no longer sold cannot be ordered nor pre-ordered', function (): void {
    $unit = orderableUnit();
    $unit->forceFill(['is_default' => false, 'status' => ArchiveStatus::Archived])->save();

    expect(fn () => app(OrderableQuantity::class)->assert($unit, 5))->toThrow(CartLineInvalid::class, 'المنتج غير متاح للطلب')
        ->and(app(OrderableQuantity::class)->preorderable($unit))->toBeFalse();
});

test('BR-O14 a pre-order line needs a coming soon product with pre-orders on and the MOQ, never stock', function (): void {
    $unit = orderableUnit(ProductStatus::ComingSoon, onHand: null);
    Product::query()->whereKey($unit->sku->product_id)->update(['preorder_enabled' => true]);
    $unit = app(CartSkuQuery::class)->find($unit->id);

    app(OrderableQuantity::class)->assert($unit, 500, CartLineKind::Preorder);

    expect(app(OrderableQuantity::class)->preorderable($unit))->toBeTrue()
        ->and(fn () => app(OrderableQuantity::class)->assert($unit, 4, CartLineKind::Preorder))->toThrow(function (CartLineInvalid $e) use ($unit): void {
            expect($e->items)->toBe([['sku_id' => $unit->sku_id, 'sku_unit_id' => $unit->id, 'reason' => 'below_moq', 'moq' => 5]]);
        });
});

test('BR-O14 a product that does not take pre-orders cannot be pre-ordered', function (ProductStatus $status, bool $enabled): void {
    $unit = orderableUnit($status);
    Product::query()->whereKey($unit->sku->product_id)->update(['preorder_enabled' => $enabled]);
    $unit = app(CartSkuQuery::class)->find($unit->id);

    expect(app(OrderableQuantity::class)->preorderable($unit))->toBeFalse()
        ->and(fn () => app(OrderableQuantity::class)->assert($unit, 5, CartLineKind::Preorder))->toThrow(function (CartLineInvalid $e) use ($unit): void {
            expect($e->items)->toBe([['sku_id' => $unit->sku_id, 'sku_unit_id' => $unit->id, 'reason' => 'preorder_unavailable']]);
        });
})->with([
    'coming soon, pre-orders off' => [ProductStatus::ComingSoon, false],
    'active' => [ProductStatus::Active, true],
    'inactive' => [ProductStatus::Inactive, true],
]);
