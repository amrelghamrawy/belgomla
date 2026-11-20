<?php

declare(strict_types=1);

use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Enums\SalesGrouping;
use App\Modules\Reports\Queries\CollectionsReportQuery;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/*
| tasks/v2/20 C.3, worked by hand. One delivered order of 10,000.00 on 2026-10-09; in the range 2026-10-08..10 it gets
| cash 1,500.00 and 250.00, InstaPay 800.00, a bank transfer 2,000.00, 300.00 drawn from the customer's credit, and a
| wallet payment of 400.00 that was voided.
|
| cash 1,750.00 · instapay 800.00 · wallet 0.00 (voided) · bank_transfer 2,000.00 · credit 300.00 = 4,850.00, the same
| total as the old pair: cash 4,550.00 (the new methods folded in) + credit 300.00.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    app(TenantContext::class)->set($this->tenant);
    $customer = $this->makeCustomer();
    $order = $this->reportOrder([[$this->reportSku('BK-1'), 100, '100.00']], '2026-10-09 08:00:00', $customer);
    $pay = fn (string $amount, CollectionMethod $method, array $extra = []): CashCollection => CashCollection::factory()
        ->create(['order_id' => $order->id, 'amount' => $amount, 'method' => $method, 'collected_at' => '2026-10-09 10:00:00', ...$extra]);

    $pay('1500.00', CollectionMethod::Cash);
    $pay('250.00', CollectionMethod::Cash);
    $pay('800.00', CollectionMethod::Instapay, ['reference' => 'IP-1']);
    $pay('2000.00', CollectionMethod::BankTransfer, ['reference' => 'TRX-1']);
    $pay('300.00', CollectionMethod::Credit, ['customer_credit_id' => CustomerCredit::factory()->create(['customer_id' => $customer->id, 'amount' => '300.00', 'remaining' => '0.00'])->id]);
    CashCollection::factory()->voided()->create(['order_id' => $order->id, 'amount' => '400.00', 'method' => CollectionMethod::Wallet, 'collected_at' => '2026-10-09 11:00:00']);

    $this->report = app(CollectionsReportQuery::class)->get(new ReportFiltersData('2026-10-08', '2026-10-10', SalesGrouping::Day, null, null, 'Africa/Cairo'))['data'];
});

test('D103 e the payments of the range per method, a voided one left out', function (): void {
    expect(array_column($this->report['by_method'], 'amount', 'method'))
        ->toBe(['cash' => '1750.00', 'instapay' => '800.00', 'wallet' => '0.00', 'bank_transfer' => '2000.00', 'credit' => '300.00']);
});

test('D103 e the methods add up to the old cash and credit pair, the new methods folded into cash', function (): void {
    expect($this->report['totals']['cash'])->toBe('4550.00')
        ->and($this->report['totals']['credit'])->toBe('300.00')
        ->and(array_reduce(array_column($this->report['by_method'], 'amount'), fn (string $sum, string $amount): string => bcadd($sum, $amount, 2), '0.00'))->toBe('4850.00');
});
