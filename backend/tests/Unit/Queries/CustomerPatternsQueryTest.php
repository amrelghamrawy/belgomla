<?php

declare(strict_types=1);

use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Enums\CustomerView;
use App\Modules\Reports\Enums\SalesGrouping;
use App\Modules\Reports\Queries\CustomerPatternsQuery;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/*
| V2 Task 16 customer patterns, now 2026-10-10 12:00 UTC (Cairo 15:00, UTC+3, D60), range 2026-10-01..10.
|
| A (group «جملة»): orders delivered 2026-09-01 (200.00, unpaid, due on delivery), 2026-09-21 (100.00, collected) and
| 2026-10-09 (300.00, unpaid, due 2026-10-20); 50.00 of the first comes back on 2026-10-10. In the range: 1 order,
| 300 − 50 = 250.00 net, average order 300.00; 3 orders over 38 days = every 19.0 days; last order 1 day ago; owes
| 150 + 300 = 450.00 of which 150.00 overdue. B: one order on 2026-08-01, collected → dormant (70 days). E: one order on
| 2026-08-20 (51 days). C: only a cancelled order (not listed). D: a pending order placed 2026-10-05 (not dormant).
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    app(TenantContext::class)->set($this->tenant);
    $this->book = $this->reportSku('BK-1');
    $this->group = CustomerGroup::factory()->create(['name' => 'جملة']);
    $this->a = $this->makeCustomer(['name' => 'مكتبة أ', 'phone' => '+201000000001', 'customer_group_id' => $this->group->id]);
    $this->b = $this->makeCustomer(['name' => 'مكتبة ب']);
    $this->e = $this->makeCustomer(['name' => 'مكتبة هـ']);
    $first = $this->reportOrder([[$this->book, 2, '100.00']], '2026-09-01 10:00:00', $this->a);
    $this->reportOrder([[$this->book, 1, '100.00']], '2026-09-21 10:00:00', $this->a, order: ['collected_amount' => '100.00', 'collection_status' => 'collected']);
    $this->reportOrder([[$this->book, 3, '100.00']], '2026-10-09 10:00:00', $this->a, order: ['due_at' => '2026-10-20 10:00:00']);
    $this->reportReturn($first, [[$first->items()->sole(), 1, '50.00']], '2026-10-10 09:00:00');
    $this->reportOrder([[$this->book, 1, '100.00']], '2026-08-01 10:00:00', $this->b, order: ['collected_amount' => '100.00', 'collection_status' => 'collected']);
    $this->reportOrder([[$this->book, 1, '100.00']], '2026-08-20 10:00:00', $this->e, order: ['collected_amount' => '100.00', 'collection_status' => 'collected']);
    $this->reportOrder([[$this->book, 1, '100.00']], null, $this->makeCustomer(['name' => 'مكتبة ج']), order: ['status' => 'cancelled']);
    $this->d = $this->makeCustomer(['name' => 'مكتبة د']);
    $this->reportOrder([[$this->book, 1, '100.00']], null, $this->d, order: ['placed_at' => '2026-10-05 10:00:00']);

    $this->filters = fn (?int $customerGroup = null): ReportFiltersData => new ReportFiltersData('2026-10-01', '2026-10-10', SalesGrouping::Day, null, $customerGroup, 'Africa/Cairo');
    $this->customers = fn (CustomerView $view = CustomerView::Top, int $dormantDays = 45, ?ReportFiltersData $filters = null): array => app(CustomerPatternsQuery::class)
        ->paginate($filters ?? ($this->filters)(), $view, $dormantDays, 1, 20)['page']->items();
});

test('a customer carries the range figures, the order rhythm and what they owe', function (): void {
    expect(($this->customers)())->toBe([[
        'customer' => ['id' => $this->a->id, 'name' => 'مكتبة أ', 'phone' => '+201000000001'],
        'customer_group' => ['id' => $this->group->id, 'name' => 'جملة'],
        'orders' => 1,
        'revenue' => '250.00',
        'average_order' => '300.00',
        'first_order_at' => '2026-09-01T10:00:00+00:00',
        'last_order_at' => '2026-10-09T10:00:00+00:00',
        'lifetime_orders' => 3,
        'average_interval_days' => 19.0,
        'days_since_last_order' => 1,
        'dormant' => false,
        'outstanding' => '450.00',
        'overdue' => '150.00',
    ]]);
});

test('the top view lists customers with an order delivered in the range, most net revenue first', function (): void {
    $this->reportOrder([[$this->book, 5, '100.00']], '2026-10-02 10:00:00', $this->b);

    expect(array_column(array_column(($this->customers)(), 'customer'), 'name'))->toBe(['مكتبة ب', 'مكتبة أ']);
});

test('the dormant view lists customers silent for more than the threshold, the longest first', function (int $days, array $names): void {
    expect(array_column(array_column(($this->customers)(CustomerView::Dormant, $days), 'customer'), 'name'))->toBe($names);
})->with([
    'default 45 days' => [45, ['مكتبة ب', 'مكتبة هـ']],
    '51 days is not more than 51' => [51, ['مكتبة ب']],
    '50 days' => [50, ['مكتبة ب', 'مكتبة هـ']],
]);

test('a single order gives no average interval, and a dormant customer is flagged', function (): void {
    $b = collect(($this->customers)(CustomerView::Dormant))->firstWhere('customer.id', $this->b->id);

    expect($b)->toMatchArray(['lifetime_orders' => 1, 'average_interval_days' => null, 'days_since_last_order' => 70, 'dormant' => true, 'orders' => 0, 'revenue' => '0.00', 'average_order' => null, 'outstanding' => '0.00']);
});

test('a pending order counts as the last order, so its customer is not dormant', function (): void {
    expect(collect(($this->customers)(CustomerView::Dormant, 5))->pluck('customer.id')->all())->not->toContain($this->d->id)
        ->and(collect(($this->customers)(CustomerView::Dormant, 4))->pluck('customer.id')->all())->toContain($this->d->id);
});

test('D60 the days since the last order are cairo days', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 20:59:00', 'UTC'));
    $before = collect(($this->customers)())->firstWhere('customer.id', $this->a->id)['days_since_last_order'];
    cache()->flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-10 21:00:00', 'UTC'));

    expect($before)->toBe(1)
        ->and(collect(($this->customers)())->firstWhere('customer.id', $this->a->id)['days_since_last_order'])->toBe(2);
});

test('BR-PR12 a customer group filter keeps that group\'s customers', function (): void {
    expect(($this->customers)(CustomerView::Dormant, filters: ($this->filters)($this->group->id)))->toBe([]);
});

test('the export reads every row', function (): void {
    expect(iterator_to_array(app(CustomerPatternsQuery::class)->rows(($this->filters)(), CustomerView::Dormant, 45), false))->toHaveCount(2);
});

test('BR-T03 another tenant\'s customers never show', function (): void {
    $other = $this->otherTenant();
    $sku = $this->reportSku('BK-1', tenant: $other);
    $this->reportOrder([[$sku, 9, '100.00']], '2026-10-09 10:00:00', $this->makeCustomer(tenant: $other), tenant: $other);

    expect(($this->customers)())->toHaveCount(1);
});

test('the report runs a fixed number of queries whatever the number of customers', function (): void {
    $few = $this->queriesRun(fn () => ($this->customers)());
    $this->reportOrder([[$this->book, 1, '100.00']], '2026-10-03 10:00:00', $this->b);
    $this->reportOrder([[$this->book, 1, '100.00']], '2026-10-04 10:00:00', $this->e);
    cache()->flush();

    expect($this->queriesRun(fn () => ($this->customers)()))->toBe($few)->toBeLessThanOrEqual(4);
});
