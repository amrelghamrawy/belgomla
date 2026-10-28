<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Customers\Queries\CartQuery;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Data\CartLineInput;
use App\Modules\Orders\Data\CheckoutLine;
use App\Modules\Orders\Exceptions\CheckoutFailed;
use App\Modules\Orders\Services\CheckoutValidator;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Pricing\Enums\PriceSource;
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

        $inputs = array_values($lines->map(fn ($line): CartLineInput => new CartLineInput($line->skuUnit, $line->quantity))->all());

        return app(CheckoutValidator::class)->validate($inputs, $stock, $this->customer->refresh(), null);
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

    expect(($this->failure)()->items)->toBe([['sku_id' => $this->sku->id, 'sku_unit_id' => $this->defaultUnitId($this->sku), 'reason' => 'below_moq', 'moq' => 5]]);
});

test('BR-INV01 BR-U03 stock exactly covering quantity × conversion factor passes', function (): void {
    SkuInventory::query()->whereKey($this->sku->id)->update(['on_hand' => 120]);
    $this->fillCart($this->customer, [$this->sku->id => 6]);

    expect(($this->validate)())->toHaveCount(1);
});

test('BR-INV01 BR-U03 one base unit short is refused with what is left in selling units', function (): void {
    SkuInventory::query()->whereKey($this->sku->id)->update(['on_hand' => 119]);
    $this->fillCart($this->customer, [$this->sku->id => 6]);

    expect(($this->failure)()->items)->toBe([['sku_id' => $this->sku->id, 'sku_unit_id' => $this->defaultUnitId($this->sku), 'reason' => 'insufficient_stock', 'requested' => 6, 'available' => 5]]);
});

test('BR-INV01 reserved units are not available', function (): void {
    SkuInventory::query()->whereKey($this->sku->id)->update(['on_hand' => 120, 'reserved' => 20]);
    $this->fillCart($this->customer, [$this->sku->id => 6]);

    expect(($this->failure)()->items)->toBe([['sku_id' => $this->sku->id, 'sku_unit_id' => $this->defaultUnitId($this->sku), 'reason' => 'insufficient_stock', 'requested' => 6, 'available' => 5]]);
});

test('BR-INV01 a sku without a stock row has nothing available', function (): void {
    $sku = $this->inTenant(fn (): Sku => Product::factory()->withSku(['moq' => 1])->create()->sku()->sole());
    $this->fillCart($this->customer, [$sku->id => 1]);

    expect(($this->failure)()->items)->toBe([['sku_id' => $sku->id, 'sku_unit_id' => $this->defaultUnitId($sku), 'reason' => 'insufficient_stock', 'requested' => 1, 'available' => 0]]);
});

test('BR-P04 D21 only active products can be ordered', function (ProductStatus $status): void {
    Product::query()->whereKey($this->sku->product_id)->update(['status' => $status]);
    $this->fillCart($this->customer, [$this->sku->id => 5]);

    expect(($this->failure)()->items)->toBe([['sku_id' => $this->sku->id, 'sku_unit_id' => $this->defaultUnitId($this->sku), 'reason' => 'product_inactive']]);
})->with(['inactive' => [ProductStatus::Inactive], 'coming soon' => [ProductStatus::ComingSoon]]);

test('BR-PR06 every failing line is reported at once, in cart order', function (): void {
    $short = $this->orderableSku(onHand: 20, name: 'كراسة');
    $inactive = $this->orderableSku(status: ProductStatus::Inactive, name: 'أطلس');
    $this->fillCart($this->customer, [$this->sku->id => 3, $short->id => 5, $inactive->id => 5]);

    $e = ($this->failure)();

    expect($e->errorCode)->toBe('checkout_failed')
        ->and($e->items)->toBe([
            ['sku_id' => $this->sku->id, 'sku_unit_id' => $this->defaultUnitId($this->sku), 'reason' => 'below_moq', 'moq' => 5],
            ['sku_id' => $short->id, 'sku_unit_id' => $this->defaultUnitId($short), 'reason' => 'insufficient_stock', 'requested' => 5, 'available' => 1],
            ['sku_id' => $inactive->id, 'sku_unit_id' => $this->defaultUnitId($inactive), 'reason' => 'product_inactive'],
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

    expect($e->items)->toBe([['sku_id' => $this->sku->id, 'sku_unit_id' => $this->defaultUnitId($this->sku), 'reason' => 'below_moq', 'moq' => 5]])
        ->and($e->details)->toBe(['mov' => ['required' => '1000.00', 'current' => '360.00']]);
});

test('BR-C05 an order that brings the exposure exactly to the credit limit passes', function (): void {
    $this->customer->forceFill(['credit_limit' => '540.00'])->save();
    $this->fillCart($this->customer, [$this->sku->id => 6]);

    expect(($this->validate)())->toHaveCount(1);
});

test('BR-C05 D78 an order that passes the credit limit is refused with the limit, the exposure and the order total', function (): void {
    $this->customer->forceFill(['credit_limit' => '700.00'])->save();
    App\Modules\Orders\Models\Order::factory()->delivered()->for($this->customer)->create(['items_total' => '160.01', 'order_total' => '160.01']);
    $this->fillCart($this->customer, [$this->sku->id => 6]);

    $e = ($this->failure)();

    expect($e->items)->toBe([])
        ->and($e->details)->toBe(['credit' => ['limit' => '700.00', 'exposure' => '160.01', 'order_total' => '540.00']]);
});

test('BR-C05 a customer without a credit limit is never refused for it', function (): void {
    App\Modules\Orders\Models\Order::factory()->delivered()->for($this->customer)->create(['items_total' => '99999.00', 'order_total' => '99999.00']);
    $this->fillCart($this->customer, [$this->sku->id => 6]);

    expect(($this->validate)())->toHaveCount(1);
});

test('BR-C05 without an exposure figure (no collections module) the limit is not enforced', function (): void {
    app()->bind(App\Modules\Orders\Contracts\CustomerExposure::class, App\Modules\Orders\Services\NullCustomerExposure::class);
    $this->customer->forceFill(['credit_limit' => '1.00'])->save();
    $this->fillCart($this->customer, [$this->sku->id => 6]);

    expect(($this->validate)())->toHaveCount(1);
});

test('BR-C05 BR-MOV02 the credit limit is reported together with the other problems', function (): void {
    ($this->mov)('1000.00');
    $this->customer->forceFill(['credit_limit' => '100.00'])->save();
    $this->fillCart($this->customer, [$this->sku->id => 4]);

    $e = ($this->failure)();

    expect($e->items)->toBe([['sku_id' => $this->sku->id, 'sku_unit_id' => $this->defaultUnitId($this->sku), 'reason' => 'below_moq', 'moq' => 5]])
        ->and($e->details)->toBe(['mov' => ['required' => '1000.00', 'current' => '360.00'], 'credit' => ['limit' => '100.00', 'exposure' => '0.00', 'order_total' => '360.00']]);
});

test('BR-O12 the line checks alone report the lines and their problems, without the minimum or the credit limit', function (): void {
    ($this->mov)('5000.00');
    $this->customer->forceFill(['credit_limit' => '1.00'])->save();
    $stock = SkuInventory::query()->whereKey($this->sku->id)->get()->keyBy('sku_id');

    $report = app(CheckoutValidator::class)->checkLines([new CartLineInput(app(CartSkuQuery::class)->findDefault($this->sku->id), 3)], $stock, null);

    expect($report->total)->toBe('270.00')
        ->and($report->problemFor($this->defaultUnitId($this->sku))?->reason)->toBe('below_moq')
        ->and([$report->movAmount, $report->mov, $report->credit])->toBe([null, null, null]);
});

test('BR-C05 BR-O12 an amount already counted in the exposure is taken out of it', function (): void {
    $this->customer->forceFill(['credit_limit' => '1000.00'])->save();
    App\Modules\Orders\Models\Order::factory()->for($this->customer)->create(['items_total' => '700.00', 'order_total' => '700.00']);

    $validator = app(CheckoutValidator::class);

    expect($validator->creditShortfall($this->customer, '900.00', '700.00'))->toBeNull()
        ->and($validator->creditShortfall($this->customer, '1000.01', '700.00'))->toBe(['limit' => '1000.00', 'exposure' => '0.00', 'order_total' => '1000.01'])
        ->and($validator->creditShortfall($this->customer, '900.00'))->toBe(['limit' => '1000.00', 'exposure' => '700.00', 'order_total' => '900.00']);
});

test('BR-MOV02 the shortfall of a total against the minimum order value', function (?string $amount, string $total, ?array $expected): void {
    expect(app(CheckoutValidator::class)->movShortfall($amount, $total))->toBe($expected);
})->with([
    'no minimum' => [null, '10.00', null],
    'exactly the minimum' => ['500.00', '500.00', null],
    'one piaster short' => ['500.00', '499.99', ['required' => '500.00', 'current' => '499.99']],
]);

/** BR-O14: a pre-order line of the preorderable SKU in its default unit, with `$open` base units already pre-ordered by others (D91). */
function preorderReport(Sku $sku, int $quantity, App\Modules\Customers\Models\Customer $customer, int $open = 0): App\Modules\Orders\Data\CheckoutReport
{
    $unit = app(CartSkuQuery::class)->findDefault($sku->id);

    return app(CheckoutValidator::class)->checkPreorder([new CartLineInput($unit, $quantity)], $customer->refresh(), [$sku->id => $open], null);
}

test('BR-O14 a pre-order line needs no stock, is priced at the tiers and reports no minimum order value', function (): void {
    TenantSetting::query()->firstOrFail()->update(['mov_enabled' => true, 'mov_amount' => '5000.00']);

    $report = preorderReport($this->preorderableSku(), 10, $this->customer);

    expect($report->problems)->toBe([])
        ->and($report->total)->toBe('850.00')
        ->and($report->mov)->toBeNull()
        ->and($report->movAmount)->toBeNull()
        ->and($report->credit)->toBeNull();
});

test('BR-O14 the pre-order cap: up to it passes, past it reports what remains', function (int $open, int $quantity, ?array $problem): void {
    $sku = $this->preorderableSku(limit: 20);

    $report = preorderReport($sku, $quantity, $this->customer, $open);

    expect(array_map(fn (App\Modules\Orders\Data\CheckoutProblem $p): array => $p->toArray(), array_values($report->problems)))->toBe($problem === null ? [] : [['sku_id' => $sku->id, 'sku_unit_id' => $this->defaultUnitId($sku), ...$problem]]);
})->with([
    'exactly at the cap' => [240, 8, null],
    'one past the cap' => [240, 9, ['reason' => 'preorder_limit', 'requested' => 9, 'remaining' => 8]],
    'the cap already passed' => [500, 5, ['reason' => 'preorder_limit', 'requested' => 5, 'remaining' => 0]],
]);

test('BR-O14 a product that no longer takes pre-orders, and the MOQ, are reported per line', function (): void {
    $closed = $this->preorderableSku();
    Product::query()->whereKey($closed->product_id)->update(['preorder_enabled' => false]);
    $small = $this->preorderableSku(name: 'أطلس');

    expect(preorderReport($closed, 5, $this->customer)->problemFor($this->defaultUnitId($closed))?->reason)->toBe('preorder_unavailable')
        ->and(preorderReport($small, 4, $this->customer)->problemFor($this->defaultUnitId($small))?->toArray())->toBe(['sku_id' => $small->id, 'sku_unit_id' => $this->defaultUnitId($small), 'reason' => 'below_moq', 'moq' => 5]);
});

test('BR-C05 a pre-order past the credit limit is reported', function (): void {
    $this->customer->forceFill(['credit_limit' => '100.00'])->save();

    expect(preorderReport($this->preorderableSku(), 5, $this->customer)->credit)->toBe(['limit' => '100.00', 'exposure' => '0.00', 'order_total' => '450.00']);
});

test('D88 a conversion only needs its lines orderable now: below the MOQ, the minimum order value and the credit limit pass', function (): void {
    $report = new App\Modules\Orders\Data\CheckoutReport([], [17 => App\Modules\Orders\Data\CheckoutProblem::belowMoq(7, 17, 5)], '10.00', '500.00', ['required' => '500.00', 'current' => '10.00'], ['limit' => '1.00', 'exposure' => '0.00', 'order_total' => '10.00']);

    expect($report->assertConvertible())->toBe([]);
});

test('D88 a conversion still refuses a line out of stock or no longer active', function (): void {
    $report = new App\Modules\Orders\Data\CheckoutReport([], [17 => App\Modules\Orders\Data\CheckoutProblem::insufficientStock(7, 17, 5, 2)], '10.00', null, null, null);

    expect(fn (): array => $report->assertConvertible())->toThrow(function (CheckoutFailed $e): void {
        expect($e->items)->toBe([['sku_id' => 7, 'sku_unit_id' => 17, 'reason' => 'insufficient_stock', 'requested' => 5, 'available' => 2]]);
    });
});

test('BR-PR14 the validator prices every line at the context it is handed', function (): void {
    $unit = app(CartSkuQuery::class)->findDefault($this->sku->id);
    $stock = collect([$this->sku->id => SkuInventory::query()->findOrFail($this->sku->id)]);

    $report = app(CheckoutValidator::class)->checkLines([new CartLineInput($unit, 5)], $stock, new PricingContext(null, [$unit->id => '77.00']));

    expect($report->lines[0]->price->unitPrice)->toBe('77.00')
        ->and($report->lines[0]->price->source)->toBe(PriceSource::Customer)
        ->and($report->total)->toBe('385.00');
});

test('BR-PR06 BR-PR15 a line is priced with the promotion in the context it is handed', function (): void {
    $this->fillCart($this->customer, [$this->sku->id => 5]);
    $lines = app(CartQuery::class)->linesOf($this->customer);
    $stock = SkuInventory::query()->whereIn('sku_id', $lines->pluck('sku_id'))->get()->keyBy('sku_id');
    $inputs = array_values($lines->map(fn ($line): CartLineInput => new CartLineInput($line->skuUnit, $line->quantity))->all());
    $context = new PricingContext(promotions: [$this->sku->id => new App\Modules\Pricing\Data\PromotionPrice(9, 'عرض', '20.00', Carbon\CarbonImmutable::parse('2026-12-01'))]);

    $line = app(CheckoutValidator::class)->validate($inputs, $stock, $this->customer->refresh(), $context)[0];

    expect($line->price->unitPrice)->toBe('72.00')
        ->and($line->price->source)->toBe(PriceSource::Promotion)
        ->and($line->price->promotion?->promotionId)->toBe(9);
});

/*
| D91 (V2 Task 13): the orderable SKU is sold by the carton of 20 (its default unit) and, below, by the piece (factor 1,
| 5.00, MOQ 10). Both draw on the same stock of pieces.
*/

test('D91 BR-U03 a piece line and a carton line of one SKU share its stock: the later line gets what is left', function (): void {
    SkuInventory::query()->whereKey($this->sku->id)->update(['on_hand' => 110]);
    $piece = $this->addUnit($this->sku, ['conversion_factor' => 1, 'base_price' => '5.00', 'moq' => 10]);
    $this->fillCart($this->customer, [$this->sku->id => 5]);
    $this->inTenant(fn () => App\Modules\Customers\Models\CartItem::factory()->create(['cart_id' => App\Modules\Customers\Models\Cart::query()->sole()->id, 'sku_id' => $this->sku->id, 'sku_unit_id' => $piece->id, 'quantity' => 11]));

    expect(($this->failure)()->items)->toBe([['sku_id' => $this->sku->id, 'sku_unit_id' => $piece->id, 'reason' => 'insufficient_stock', 'requested' => 11, 'available' => 10]]);
});

test('D91 BR-U03 piece and carton lines whose base units fit the stock together pass', function (): void {
    SkuInventory::query()->whereKey($this->sku->id)->update(['on_hand' => 110]);
    $piece = $this->addUnit($this->sku, ['conversion_factor' => 1, 'base_price' => '5.00', 'moq' => 10]);
    $this->fillCart($this->customer, [$this->sku->id => 5]);
    $this->inTenant(fn () => App\Modules\Customers\Models\CartItem::factory()->create(['cart_id' => App\Modules\Customers\Models\Cart::query()->sole()->id, 'sku_id' => $this->sku->id, 'sku_unit_id' => $piece->id, 'quantity' => 10]));

    $lines = ($this->validate)();

    expect(array_map(fn (CheckoutLine $line): array => [$line->unit->id, $line->quantityBase(), $line->price->unitPrice], $lines))
        ->toBe([[$this->defaultUnitId($this->sku), 100, '90.00'], [$piece->id, 10, '5.00']]);
});

test('D91 BR-MOQ01 the MOQ is the line unit\'s own', function (int $quantity, ?array $problem): void {
    $piece = $this->addUnit($this->sku, ['conversion_factor' => 1, 'base_price' => '5.00', 'moq' => 10]);
    $this->inTenant(fn () => App\Modules\Customers\Models\CartItem::factory()->create(['cart_id' => App\Modules\Customers\Models\Cart::factory()->create(['customer_id' => $this->customer->id])->id, 'sku_id' => $this->sku->id, 'sku_unit_id' => $piece->id, 'quantity' => $quantity]));

    $report = app(CheckoutValidator::class)->checkLines([new CartLineInput(app(CartSkuQuery::class)->find($piece->id), $quantity)], SkuInventory::query()->get()->keyBy('sku_id'), null);

    expect($report->problemFor($piece->id)?->toArray())->toBe($problem === null ? null : ['sku_id' => $this->sku->id, 'sku_unit_id' => $piece->id, ...$problem]);
})->with([
    'the piece MOQ (below the carton MOQ of 5 in pieces)' => [10, null],
    'one below the piece MOQ' => [9, ['reason' => 'below_moq', 'moq' => 10]],
]);

test('D91 a unit no longer sold is refused like an inactive product', function (): void {
    $piece = $this->addUnit($this->sku, ['conversion_factor' => 1, 'base_price' => '5.00', 'status' => App\Modules\Catalog\Enums\ArchiveStatus::Archived]);

    $report = app(CheckoutValidator::class)->checkLines([new CartLineInput(app(CartSkuQuery::class)->find($piece->id), 10)], SkuInventory::query()->get()->keyBy('sku_id'), null);

    expect($report->problemFor($piece->id)?->reason)->toBe('product_inactive');
});

test('D91 BR-O14 the pre-order cap is in the default unit and counts the base units of every unit', function (): void {
    $sku = $this->preorderableSku(limit: 2);
    $piece = $this->addUnit($sku, ['conversion_factor' => 1, 'base_price' => '5.00', 'moq' => 1]);
    $line = new CartLineInput(app(CartSkuQuery::class)->find($piece->id), 21);

    $report = app(CheckoutValidator::class)->checkPreorder([$line], $this->customer->refresh(), [$sku->id => 20], null);

    expect($report->problemFor($piece->id)?->toArray())->toBe(['sku_id' => $sku->id, 'sku_unit_id' => $piece->id, 'reason' => 'preorder_limit', 'requested' => 21, 'remaining' => 20]);
});
