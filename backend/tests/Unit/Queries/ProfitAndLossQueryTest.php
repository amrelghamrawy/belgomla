<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Enums\SalesGrouping;
use App\Modules\Reports\Queries\ProfitAndLossQuery;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/*
| V2 Task 19 (D100) on the hand-worked case of tasks/v2/19-expenses.md. Range 8–10 October 2026 (Cairo, UTC+3 then);
| the previous range is 5–7 October.
|
| net sales 10,000.00 (100 books at 100.00, delivered 9 Oct); cost 100 × 72.50 = 7,250.00 → gross profit 2,750.00
| stock: damaged −5 at 5.2618 = −26.309; found +2 at 5.2500 = +10.50 → losses 15.809 → 15.81
| expenses: rent 1,500.00 (8 Oct) + transport 350.50 + salaries 600.00 (10 Oct) = 2,450.50 (a voided 120.25 ignored)
| net profit 2,750.00 − 15.81 − 2,450.50 = 283.69; margin 283.69 ÷ 10,000.00 = 2.8369 % → 2.84
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    app(TenantContext::class)->set($this->tenant);
    $this->book = $this->reportSku('BK-1', price: '100.00');
    $this->order = $this->reportOrder([[$this->book, 100, '100.00']], '2026-10-09 10:00:00');
    OrderItem::query()->where('order_id', $this->order->id)->update(['unit_cost' => '72.5000']);
    $this->adjustment = fn (int $quantity, ?string $cost, ?string $reason, string $at = '2026-10-09 10:00:00'): InventoryMovement => InventoryMovement::factory()->create([
        'sku_id' => $this->book->id, 'type' => 'adjustment', 'quantity' => $quantity, 'on_hand_after' => 100,
        'reason' => 'x', 'reason_code' => $reason, 'unit_cost' => $cost, 'created_at' => $at,
    ]);
    ($this->adjustment)(-5, '5.2618', 'damaged');
    ($this->adjustment)(2, '5.2500', 'found');
    Expense::factory()->create(['spent_on' => '2026-10-08', 'amount' => '1500.00', 'category' => 'rent']);
    Expense::factory()->create(['spent_on' => '2026-10-10', 'amount' => '350.50', 'category' => 'transport']);
    Expense::factory()->create(['spent_on' => '2026-10-10', 'amount' => '600.00', 'category' => 'salaries']);
    Expense::factory()->voided()->create(['spent_on' => '2026-10-09', 'amount' => '120.25', 'category' => 'transport']);

    $this->report = fn (?int $category = null, ?int $customerGroup = null): array => app(ProfitAndLossQuery::class)
        ->get(new ReportFiltersData('2026-10-08', '2026-10-10', SalesGrouping::Day, $category, $customerGroup, 'Africa/Cairo'))['data'];
});

test('D100 h every line of the statement, worked out by hand', function (): void {
    expect(($this->report)()['current'])->toBe([
        'from' => '2026-10-08',
        'to' => '2026-10-10',
        'net_sales' => '10000.00',
        'cost_of_goods' => '7250.00',
        'gross_profit' => '2750.00',
        'stock_losses' => '15.81',
        'losses_by_reason' => ['damaged' => '26.31', 'found' => '-10.50'],
        'expenses' => '2450.50',
        'expenses_by_category' => ['rent' => '1500.00', 'salaries' => '600.00', 'transport' => '350.50'],
        'net_profit' => '283.69',
        'net_margin' => '2.84',
        'uncosted_lines' => 0,
        'uncosted_adjustments' => 0,
    ]);
});

test('D100 h a loss: the same with rent 3,000.00 is −1,216.31', function (): void {
    Expense::query()->where('category', 'rent')->update(['amount' => '3000.00']);

    // 2,750.00 − 15.81 − 3,950.50 = −1,216.31; −1,216.31 ÷ 10,000.00 = −12.1631 % → −12.16
    expect(($this->report)()['current'])->toMatchArray(['expenses' => '3950.50', 'net_profit' => '-1216.31', 'net_margin' => '-12.16']);
});

test('D97 c D100 g the import adjustments are data entry, not a loss', function (): void {
    ($this->adjustment)(-400, '5.0000', 'import');

    expect(($this->report)()['current'])->toMatchArray(['stock_losses' => '15.81', 'net_profit' => '283.69']);
});

test('D100 g an adjustment without a cost leaves the losses and the net unknown, the expenses still shown', function (): void {
    ($this->adjustment)(-1, null, 'expired');

    expect(($this->report)()['current'])->toMatchArray([
        'gross_profit' => '2750.00', 'stock_losses' => null, 'expenses' => '2450.50', 'net_profit' => null, 'net_margin' => null, 'uncosted_adjustments' => 1,
    ]);
});

test('D98 D100 h a sale line without a cost leaves the gross and the net unknown', function (): void {
    OrderItem::query()->where('order_id', $this->order->id)->update(['unit_cost' => null]);

    expect(($this->report)()['current'])->toMatchArray(['gross_profit' => null, 'net_profit' => null, 'net_margin' => null, 'uncosted_lines' => 1, 'expenses' => '2450.50']);
});

test('D60 D100 e an expense counts on its day: the first and last day of the range in, the day before out', function (): void {
    Expense::factory()->create(['spent_on' => '2026-10-07', 'amount' => '77.00', 'category' => 'fees']);

    $report = ($this->report)();

    expect($report['current']['expenses'])->toBe('2450.50')
        ->and($report['previous']['expenses'])->toBe('77.00')
        ->and($report['previous'])->toMatchArray(['from' => '2026-10-05', 'to' => '2026-10-07', 'net_sales' => '0.00', 'net_profit' => '-77.00', 'net_margin' => null]);
});

test('D60 an adjustment late on the last Cairo day counts, one just after midnight Cairo does not', function (): void {
    // 10 Oct 23:30 Cairo = 20:30 UTC (in); 11 Oct 00:10 Cairo = 10 Oct 21:10 UTC (out)
    ($this->adjustment)(-1, '1.0000', 'damaged', '2026-10-10 20:30:00');
    ($this->adjustment)(-1, '9.0000', 'damaged', '2026-10-10 21:10:00');

    expect(($this->report)()['current']['losses_by_reason']['damaged'])->toBe('27.31');
});

test('D100 j the category and customer group filters do not change the statement', function (): void {
    $other = Category::factory()->create();

    expect(($this->report)($other->id, 999)['current'])->toBe(($this->report)()['current']);
});

test('adjustments made before reason codes are grouped as unspecified', function (): void {
    ($this->adjustment)(-2, '3.0000', null);

    expect(($this->report)()['current']['losses_by_reason'])->toBe(['damaged' => '26.31', 'found' => '-10.50', 'unspecified' => '6.00']);
});
