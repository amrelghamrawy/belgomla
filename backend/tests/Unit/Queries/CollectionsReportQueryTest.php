<?php

declare(strict_types=1);

use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Enums\SalesGrouping;
use App\Modules\Reports\Queries\CollectionsReportQuery;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/*
| V2 Task 16 collections, now 2026-10-10 12:00 UTC, range 2026-10-08..10 by Cairo day (UTC+3, D60).
|
| A1 (customer A, group «جملة»): delivered 2026-10-08 for 300.00, 200.00 cash the same day (and 50.00 voided); 50.00 of
| it comes back on 2026-10-10 and is paid back in cash → owes 300 − 50 − 150 = 100.00, due on delivery (overdue, 1–30).
| B1 (customer B): delivered 2026-10-09 for 130.00, 30.00 drawn from B's credit that day, 100.00 cash at 21:30 UTC
| (00:30 on 2026-10-10 in Cairo).
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    app(TenantContext::class)->set($this->tenant);
    $book = $this->reportSku('BK-1');
    $this->group = CustomerGroup::factory()->create();
    $a = $this->makeCustomer(['customer_group_id' => $this->group->id]);
    $b = $this->makeCustomer();
    $a1 = $this->reportOrder([[$book, 3, '100.00']], '2026-10-08 10:00:00', $a, order: ['collected_amount' => '150.00', 'collection_status' => 'partially_collected']);
    $b1 = $this->reportOrder([[$book, 1, '130.00']], '2026-10-09 10:00:00', $b, order: ['collected_amount' => '130.00', 'collection_status' => 'collected']);
    CashCollection::factory()->create(['order_id' => $a1->id, 'amount' => '200.00', 'collected_at' => '2026-10-08 11:00:00']);
    CashCollection::factory()->voided()->create(['order_id' => $a1->id, 'amount' => '50.00', 'collected_at' => '2026-10-08 12:00:00']);
    $return = $this->reportReturn($a1, [[$a1->items()->sole(), 1, '50.00']], '2026-10-10 09:00:00');
    CashCollection::factory()->refund($return->id)->create(['order_id' => $a1->id, 'amount' => '50.00', 'collected_at' => '2026-10-10 09:05:00']);
    $credit = CustomerCredit::factory()->create(['customer_id' => $b->id, 'amount' => '30.00', 'remaining' => '0.00']);
    CashCollection::factory()->create(['order_id' => $b1->id, 'amount' => '30.00', 'method' => CollectionMethod::Credit, 'customer_credit_id' => $credit->id, 'collected_at' => '2026-10-09 10:30:00']);
    CashCollection::factory()->create(['order_id' => $b1->id, 'amount' => '100.00', 'collected_at' => '2026-10-09 21:30:00']);

    $this->report = fn (?int $customerGroup = null, SalesGrouping $group = SalesGrouping::Day): array => app(CollectionsReportQuery::class)
        ->get(new ReportFiltersData('2026-10-08', '2026-10-10', $group, 77, $customerGroup, 'Africa/Cairo'))['data'];
});

test('BR-C02 BR-R04 each cairo day shows cash, credit drawn, refunds as negative and the net, next to the net sales delivered', function (): void {
    expect(($this->report)()['buckets'])->toBe([
        ['period_start' => '2026-10-08', 'cash' => '200.00', 'credit' => '0.00', 'refunds' => '0.00', 'net' => '200.00', 'delivered' => '300.00'],
        ['period_start' => '2026-10-09', 'cash' => '0.00', 'credit' => '30.00', 'refunds' => '0.00', 'net' => '30.00', 'delivered' => '130.00'],
        ['period_start' => '2026-10-10', 'cash' => '100.00', 'credit' => '0.00', 'refunds' => '-50.00', 'net' => '50.00', 'delivered' => '-50.00'],
    ]);
});

test('the totals carry the collection rate, net collected over net delivered', function (): void {
    expect(($this->report)()['totals'])->toBe(['cash' => '300.00', 'credit' => '30.00', 'refunds' => '-50.00', 'net' => '280.00', 'delivered' => '380.00', 'collection_rate' => '73.68']);
});

test('BR-C06 D59 the aging snapshot sums what every customer owes now by days past due', function (): void {
    expect(($this->report)()['aging'])->toBe(['current' => '0.00', '0_30' => '100.00', '31_60' => '0.00', '61_90' => '0.00', 'over_90' => '0.00', 'overdue' => '100.00', 'outstanding' => '100.00']);
});

test('BR-PR12 a customer group filter keeps that group\'s money and sales', function (): void {
    $report = ($this->report)($this->group->id);

    expect($report['totals'])->toBe(['cash' => '200.00', 'credit' => '0.00', 'refunds' => '-50.00', 'net' => '150.00', 'delivered' => '250.00', 'collection_rate' => '60.00'])
        ->and($report['aging']['outstanding'])->toBe('100.00');
});

test('a group without debts has an empty aging', function (): void {
    expect(($this->report)(CustomerGroup::factory()->create()->id)['aging']['outstanding'])->toBe('0.00');
});

test('no delivery in the range gives no collection rate', function (): void {
    expect(app(CollectionsReportQuery::class)->get(new ReportFiltersData('2026-09-01', '2026-09-02', SalesGrouping::Day, null, null, 'Africa/Cairo'))['data']['totals']['collection_rate'])->toBeNull();
});

test('D58 the buckets can be saturday weeks', function (): void {
    expect(array_column(($this->report)(group: SalesGrouping::Week)['buckets'], 'net', 'period_start'))->toBe(['2026-10-03' => '230.00', '2026-10-10' => '50.00']);
});

test('BR-T03 another tenant\'s collections never count', function (): void {
    $other = $this->otherTenant();
    $order = $this->reportOrder([[$this->reportSku('BK-1', tenant: $other), 1, '100.00']], '2026-10-09 10:00:00', $this->makeCustomer(tenant: $other), tenant: $other);
    $this->inTenant(fn () => CashCollection::factory()->create(['order_id' => $order->id, 'amount' => '100.00', 'collected_at' => '2026-10-09 11:00:00']), $other);

    expect(($this->report)()['totals']['cash'])->toBe('300.00');
});

test('the report runs a fixed number of queries', function (): void {
    expect($this->queriesRun(fn () => ($this->report)()))->toBeLessThanOrEqual(6);
});
