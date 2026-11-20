<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Enums\SalesGrouping;
use App\Modules\Reports\Queries\InventoryAnalyticsQuery;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/*
| V2 Task 16 inventory analytics, now 2026-10-10 12:00 UTC, range 2026-10-08..10 (3 Cairo days, UTC+3, D60).
|
| BK-1: 100 on hand (40 reserved) at 100.00 a piece, 6 delivered on 2026-10-08 → 2 a day, 60 available = 30.0 days of
| cover, stock value 10,000.00. PN-1: 20 on hand at 50.00, never sold → dead stock worth 1,000.00. OLD-1: 5 on hand at
| 10.00, last sold 2026-08-01 (70 days ago) → dead. NEW-1: 5 on hand, last sold 2026-09-10 (30 days ago) → alive.
| NIL-1: nothing on hand, never sold → not dead.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    app(TenantContext::class)->set($this->tenant);
    $this->books = Category::factory()->inSection('books')->create();
    $this->book = $this->reportSku('BK-1', category: $this->books, onHand: 100);
    SkuInventory::query()->where('sku_id', $this->book->id)->update(['reserved' => 40]);
    $this->pen = $this->reportSku('PN-1', 'stationery', '50.00', onHand: 20);
    $this->old = $this->reportSku('OLD-1', 'stationery', '10.00', onHand: 5);
    $this->recent = $this->reportSku('NEW-1', 'stationery', '10.00', onHand: 5);
    $this->reportSku('NIL-1', 'stationery', '10.00');
    $this->customer = $this->makeCustomer();
    $this->reportOrder([[$this->book, 6, '100.00']], '2026-10-08 10:00:00', $this->customer);
    $this->reportOrder([[$this->old, 1, '10.00']], '2026-08-01 10:00:00', $this->customer);
    $this->reportOrder([[$this->recent, 1, '10.00']], '2026-09-10 10:00:00', $this->customer);

    $this->filters = fn (?int $category = null): ReportFiltersData => new ReportFiltersData('2026-10-08', '2026-10-10', SalesGrouping::Day, $category, null, 'Africa/Cairo');
    $this->inventory = fn (int $deadDays = 60, bool $deadOnly = false, ?ReportFiltersData $filters = null): array => app(InventoryAnalyticsQuery::class)
        ->paginate($filters ?? ($this->filters)(), $deadDays, $deadOnly, 1, 20)['page']->items();
    $this->row = fn (string $code, int $deadDays = 60): ?array => collect(($this->inventory)($deadDays))->firstWhere('sku_code', $code);
});

test('each sku carries its stock, its value at the base price, its sales, days of cover and movements', function (): void {
    InventoryMovement::factory()->create(['sku_id' => $this->book->id, 'type' => MovementType::Opening, 'quantity' => 100, 'created_at' => '2026-10-08 08:00:00']);
    InventoryMovement::factory()->create(['sku_id' => $this->book->id, 'type' => MovementType::Sale, 'quantity' => -6, 'on_hand_after' => 94, 'created_at' => '2026-10-08 10:00:00']);
    InventoryMovement::factory()->create(['sku_id' => $this->book->id, 'type' => MovementType::Adjustment, 'quantity' => 6, 'reason' => 'جرد', 'created_at' => '2026-10-09 10:00:00']);
    InventoryMovement::factory()->create(['sku_id' => $this->book->id, 'type' => MovementType::Adjustment, 'quantity' => 9, 'reason' => 'جرد', 'created_at' => '2026-10-01 10:00:00']);

    expect(($this->row)('BK-1'))->toBe([
        'sku_id' => $this->book->id,
        'product_id' => $this->book->product_id,
        'product_name' => 'منتج BK-1',
        'sku_code' => 'BK-1',
        'sku_name' => null,
        'on_hand' => 100,
        'available' => 60,
        'stock_value' => '10000.00',
        'sold_base' => 6,
        'days_of_cover' => 30.0,
        'last_sold_at' => '2026-10-08T10:00:00+00:00',
        'dead' => false,
        'movements' => ['opening' => 100, 'adjustment' => 6, 'sale' => -6, 'return' => 0, 'receive' => 0],
    ]);
});

test('D94 the stock value is at the default unit\'s base price per base unit', function (): void {
    $carton = $this->orderableSku(['code' => 'CT-1']);

    expect(($this->row)('CT-1')['stock_value'])->toBe('1800.00')
        ->and($carton->inventory?->on_hand)->toBe(400);
});

test('a sku that sold nothing in the range has no days of cover (shown as infinite)', function (): void {
    expect(($this->row)('PN-1'))->toMatchArray(['sold_base' => 0, 'days_of_cover' => null]);
});

test('dead stock is stock on hand with no sale for more than the threshold', function (string $code, int $days, bool $dead): void {
    expect(($this->row)($code, $days)['dead'])->toBe($dead);
})->with([
    'never sold' => ['PN-1', 60, true],
    'last sold 70 days ago' => ['OLD-1', 60, true],
    'last sold 30 days ago' => ['NEW-1', 60, false],
    'last sold 30 days ago, threshold 29' => ['NEW-1', 29, true],
    'last sold 30 days ago, threshold 31' => ['NEW-1', 31, false],
    'nothing on hand' => ['NIL-1', 60, false],
    'sold in the range' => ['BK-1', 60, false],
]);

test('dead only keeps the dead stock, highest value first', function (): void {
    expect(array_column(($this->inventory)(deadOnly: true), 'sku_code'))->toBe(['PN-1', 'OLD-1']);
});

test('D93 units sold inside bundles count as sales, and bundles are not listed', function (): void {
    $set = Product::factory()->bundle()->withSku(['code' => 'SET-1', 'conversion_factor' => 1])->create()->skus()->sole();
    $this->bundleOrder($set, [$this->defaultUnitId($this->pen) => 2], bundles: 3, delivered: 3, deliveredAt: '2026-10-09 10:00:00');

    expect(($this->row)('PN-1'))->toMatchArray(['sold_base' => 6, 'dead' => false, 'days_of_cover' => 10.0])
        ->and(($this->row)('SET-1'))->toBeNull();
});

test('the summary adds up every sku of the filter', function (): void {
    InventoryMovement::factory()->create(['sku_id' => $this->pen->id, 'type' => MovementType::Return, 'quantity' => 2, 'created_at' => '2026-10-09 10:00:00']);

    expect(app(InventoryAnalyticsQuery::class)->summary(($this->filters)(), 60, false)['data'])->toBe([
        'skus' => 5,
        'on_hand' => 130,
        'stock_value' => '11100.00',
        'dead_count' => 2,
        'dead_value' => '1050.00',
        'sold_base' => 6,
        'movements' => ['opening' => 0, 'adjustment' => 0, 'sale' => 0, 'return' => 2, 'receive' => 0],
    ]);
});

test('BR-P06 a category filter keeps the skus of that category', function (): void {
    expect(array_column(($this->inventory)(filters: ($this->filters)($this->books->id)), 'sku_code'))->toBe(['BK-1']);
});

test('the rows are sorted by stock value, highest first, then by code', function (): void {
    expect(array_column(($this->inventory)(), 'sku_code'))->toBe(['BK-1', 'PN-1', 'NEW-1', 'OLD-1', 'NIL-1']);
});

test('the export reads every row', function (): void {
    expect(iterator_to_array(app(InventoryAnalyticsQuery::class)->rows(($this->filters)(), 60, true), false))->toHaveCount(2);
});

test('BR-T03 another tenant\'s skus and sales never count', function (): void {
    $other = $this->otherTenant();
    $this->reportSku('BK-1', onHand: 999, tenant: $other);

    expect(collect(($this->inventory)())->where('sku_code', 'BK-1')->sole()['on_hand'])->toBe(100);
});

test('the report runs a fixed number of queries whatever the number of skus', function (): void {
    $few = $this->queriesRun(fn () => ($this->inventory)());
    $this->reportSku('XT-1', onHand: 3);
    $this->reportSku('XT-2', onHand: 4);
    cache()->flush();

    expect($this->queriesRun(fn () => ($this->inventory)()))->toBe($few)->toBeLessThanOrEqual(4);
});
