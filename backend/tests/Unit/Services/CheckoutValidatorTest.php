<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Queries\CartQuery;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Data\CheckoutLine;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Services\CheckoutValidator;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->customer = $this->makeCustomer();
    $this->sku = $this->orderableSku();
    app(TenantContext::class)->set($this->tenant);

    /** Validates the customer's cart against the stock rows as they are now (as if just locked). */
    $this->validate = function (): array {
        $lines = app(CartQuery::class)->linesOf($this->customer);
        $stock = SkuInventory::query()->whereIn('sku_id', $lines->pluck('sku_id'))->get()->keyBy('sku_id');

        return app(CheckoutValidator::class)->validate($lines, $stock);
    };
    $this->failure = function (): CheckoutFailed {
        try {
            ($this->validate)();
        } catch (CheckoutFailed $e) {
            return $e;
        }

        throw new RuntimeException('The cart passed validation.');
    };
    $this->mov = fn (?string $amount, bool $enabled = true) => TenantSetting::query()->whereKey($this->tenant->id)->update(['mov_enabled' => $enabled, 'mov_amount' => $amount]);
});

test('BR-PR06 valid lines come back priced, with the base units to reserve', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 12]);

    $lines = ($this->validate)();

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toBeInstanceOf(CheckoutLine::class)
        ->and($lines[0]->sku->id)->toBe($this->sku->id)
        ->and($lines[0]->quantity)->toBe(12)
        ->and($lines[0]->quantityBase())->toBe(240)
        ->and($lines[0]->price->unitPrice)->toBe('85.00')
        ->and($lines[0]->price->lineTotal)->toBe('1020.00');
});

test('BR-MOQ01 a quantity equal to the moq passes', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 5]);

    expect(($this->validate)())->toHaveCount(1);
});

test('BR-MOQ01 one unit under the moq is refused', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 4]);

    expect(($this->failure)()->items)->toBe([['sku_id' => $this->sku->id, 'reason' => 'below_moq', 'moq' => 5]]);
});

test('BR-INV01 BR-U03 stock exactly covering quantity × conversion factor passes', function (): void {
    SkuInventory::query()->whereKey($this->sku->id)->update(['on_hand' => 120]);
    $this->fillCart($this->customer, [$this->sku->id => 6]);

    expect(($this->validate)())->toHaveCount(1);
});

test('BR-INV01 BR-U03 one base unit short is refused with what is left in selling units', function (): void {
    SkuInventory::query()->whereKey($this->sku->id)->update(['on_hand' => 119]);
    $this->fillCart($this->customer, [$this->sku->id => 6]);

    expect(($this->failure)()->items)->toBe([['sku_id' => $this->sku->id, 'reason' => 'insufficient_stock', 'requested' => 6, 'available' => 5]]);
});

test('BR-INV01 reserved units are not available', function (): void {
    SkuInventory::query()->whereKey($this->sku->id)->update(['on_hand' => 120, 'reserved' => 20]);
    $this->fillCart($this->customer, [$this->sku->id => 6]);

    expect(($this->failure)()->items)->toBe([['sku_id' => $this->sku->id, 'reason' => 'insufficient_stock', 'requested' => 6, 'available' => 5]]);
});

test('BR-INV01 a sku without a stock row has nothing available', function (): void {
    $sku = $this->inTenant(fn (): Sku => Product::factory()->withSku(['moq' => 1])->create()->sku()->sole());
    $this->fillCart($this->customer, [$sku->id => 1]);

    expect(($this->failure)()->items)->toBe([['sku_id' => $sku->id, 'reason' => 'insufficient_stock', 'requested' => 1, 'available' => 0]]);
});

test('BR-P04 D21 only active products can be ordered', function (ProductStatus $status): void {
    Product::query()->whereKey($this->sku->product_id)->update(['status' => $status]);
    $this->fillCart($this->customer, [$this->sku->id => 5]);

    expect(($this->failure)()->items)->toBe([['sku_id' => $this->sku->id, 'reason' => 'product_inactive']]);
})->with(['inactive' => [ProductStatus::Inactive], 'coming soon' => [ProductStatus::ComingSoon]]);

test('BR-PR06 every failing line is reported at once, in cart order', function (): void {
    $short = $this->orderableSku(onHand: 20, name: 'كراسة');
    $inactive = $this->orderableSku(status: ProductStatus::Inactive, name: 'أطلس');
    $this->fillCart($this->customer, [$this->sku->id => 3, $short->id => 5, $inactive->id => 5]);

    $e = ($this->failure)();

    expect($e->errorCode)->toBe('checkout_failed')
        ->and($e->items)->toBe([
            ['sku_id' => $this->sku->id, 'reason' => 'below_moq', 'moq' => 5],
            ['sku_id' => $short->id, 'reason' => 'insufficient_stock', 'requested' => 5, 'available' => 1],
            ['sku_id' => $inactive->id, 'reason' => 'product_inactive'],
        ])
        ->and($e->details)->toBeNull();
});

test('BR-MOV02 a total equal to the minimum order value passes', function (): void {
    ($this->mov)('1020.00');
    $this->fillCart($this->customer, [$this->sku->id => 12]);

    expect(($this->validate)())->toHaveCount(1);
});

test('BR-MOV02 a total one piaster under the minimum order value is refused', function (): void {
    ($this->mov)('1020.01');
    $this->fillCart($this->customer, [$this->sku->id => 12]);

    expect(($this->failure)())
        ->items->toBe([])
        ->details->toBe(['mov' => ['required' => '1020.01', 'current' => '1020.00']]);
});

test('BR-MOV01 the minimum order value is ignored when off or without an amount', function (bool $enabled, ?string $amount): void {
    ($this->mov)($amount, $enabled);
    $this->fillCart($this->customer, [$this->sku->id => 5]);

    expect(($this->validate)())->toHaveCount(1);
})->with(['disabled' => [false, '5000.00'], 'enabled without amount' => [true, null]]);

test('BR-MOV02 line problems and the minimum order value are reported together', function (): void {
    ($this->mov)('1000.00');
    $this->fillCart($this->customer, [$this->sku->id => 4]);

    $e = ($this->failure)();

    expect($e->items)->toBe([['sku_id' => $this->sku->id, 'reason' => 'below_moq', 'moq' => 5]])
        ->and($e->details)->toBe(['mov' => ['required' => '1000.00', 'current' => '360.00']]);
});
