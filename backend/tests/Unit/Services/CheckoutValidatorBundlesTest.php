<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Queries\CartSkuQuery;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Orders\Data\CartLineInput;
use App\Modules\Orders\Data\CheckoutComponent;
use App\Modules\Orders\Services\CheckoutValidator;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Tenancy\TenantContext;

/*
| BR-P15, D93: a bundle line is checked on its components' stock, shared with the order's loose lines of those SKUs.
| The bundle: one carton (20 pieces) of عربي and two cartons of رياضيات at a fixed 150.00; with 400 pieces of each
| in stock, 10 bundles fit (رياضيات: 400 / 40).
*/

beforeEach(function (): void {
    $this->arabic = $this->orderableSku(['code' => 'AR-1'], name: 'عربي');
    $this->maths = $this->orderableSku(['code' => 'MA-1'], name: 'رياضيات');
    $this->bundle = $this->gradeSet([$this->arabic->id => 1, $this->maths->id => 2], value: '150.00');
    app(TenantContext::class)->set($this->tenant);

    $this->line = fn (int $skuId, int $quantity): CartLineInput => new CartLineInput(app(CartSkuQuery::class)->find($this->defaultUnitId($skuId)), $quantity);
    $this->check = fn (CartLineInput ...$lines) => app(CheckoutValidator::class)->checkLines(array_values($lines), SkuInventory::query()->get()->keyBy('sku_id'), null);
    $this->bundleSkuId = $this->bundleSku($this->bundle)->id;
});

test('BR-P15 a bundle line is priced as its own unit and passes while its components fit', function (int $quantity): void {
    $report = ($this->check)(($this->line)($this->bundleSkuId, $quantity));

    expect($report->problems)->toBe([])
        ->and($report->lines[0]->price->unitPrice)->toBe('150.00')
        ->and($report->total)->toBe(bcmul('150.00', (string) $quantity, 2));
})->with(['one bundle' => [1], 'exactly what the stock allows' => [10]]);

test('BR-INV01 D93 a bundle the components cannot cover is short, with the bundles that still fit', function (): void {
    $report = ($this->check)(($this->line)($this->bundleSkuId, 11));

    expect(array_map(fn ($problem): array => $problem->toArray(), $report->problems))->toBe([
        $this->defaultUnitId($this->bundleSkuId) => ['sku_id' => $this->bundleSkuId, 'sku_unit_id' => $this->defaultUnitId($this->bundleSkuId), 'reason' => 'insufficient_stock', 'requested' => 11, 'available' => 10],
    ]);
});

test('D93 a bundle and a loose copy of a component share its stock; the shortfall is reported on the bundle line', function (): void {
    $report = ($this->check)(($this->line)($this->bundleSkuId, 8), ($this->line)($this->maths->id, 6)); // the 6 loose cartons leave 280 pieces: 7 bundles

    expect(array_keys($report->problems))->toBe([$this->defaultUnitId($this->bundleSkuId)])
        ->and($report->problems[$this->defaultUnitId($this->bundleSkuId)]->extra)->toBe(['requested' => 8, 'available' => 7]);
});

test('D93 the loose line gets its stock first whatever the line order', function (): void {
    $report = ($this->check)(($this->line)($this->maths->id, 6), ($this->line)($this->bundleSkuId, 7));

    expect($report->problems)->toBe([]);
});

test('D93 a bundle whose component can no longer be ordered is refused as product_inactive', function (): void {
    Product::query()->whereKey($this->maths->product_id)->update(['status' => ProductStatus::Inactive]);

    $report = ($this->check)(($this->line)($this->bundleSkuId, 1));

    expect($report->problems[$this->defaultUnitId($this->bundleSkuId)]->reason)->toBe('product_inactive');
});

test('BR-P04 an inactive bundle is refused', function (): void {
    Product::query()->whereKey($this->bundle->id)->update(['status' => ProductStatus::Inactive]);

    expect(($this->check)(($this->line)($this->bundleSkuId, 1))->problems[$this->defaultUnitId($this->bundleSkuId)]->reason)->toBe('product_inactive');
});

test('BR-MOQ01 a bundle has its own MOQ; the components\' MOQs do not apply', function (): void {
    $bundle = $this->gradeSet([$this->arabic->id => 1], value: '80.00', moq: 3, code: 'SET-2');
    $skuId = $this->bundleSku($bundle)->id;

    expect(($this->check)(($this->line)($skuId, 2))->problems[$this->defaultUnitId($skuId)]->toArray())->toMatchArray(['reason' => 'below_moq', 'moq' => 3])
        ->and(($this->check)(($this->line)($skuId, 3))->problems)->toBe([]);
});

test('BR-PR14 D93 each component is priced on its own at the customer\'s prices, for the split', function (): void {
    $report = app(CheckoutValidator::class)->checkLines([($this->line)($this->bundleSkuId, 2)], SkuInventory::query()->get()->keyBy('sku_id'), new PricingContext('10.00'));

    expect(array_map(fn (CheckoutComponent $component): array => [$component->sku->code, $component->perBundle, $component->weight], $report->lines[0]->components))->toBe([
        ['AR-1', 1, '81.00'],
        ['MA-1', 2, '162.00'],
    ])->and($report->lines[0]->price->unitPrice)->toBe('135.00'); // the group % on the bundle price too
});

test('D93 a bundle line takes each component\'s base units', function (): void {
    $line = ($this->line)($this->bundleSkuId, 3);

    expect(array_map(fn (array $part): array => [$part[0]->code, $part[1]], $line->parts()))->toBe([['AR-1', 60], ['MA-1', 120]])
        ->and($line->pricedSkuIds())->toBe([$this->bundleSkuId, $this->arabic->id, $this->maths->id])
        ->and(($this->line)($this->arabic->id, 2)->parts()[0][1])->toBe(40);
});
