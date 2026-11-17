<?php

declare(strict_types=1);

use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Queries\ExpenseTotalsQuery;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    Expense::factory()->create(['spent_on' => '2026-10-01', 'amount' => '1500.00', 'category' => 'rent']);
    Expense::factory()->create(['spent_on' => '2026-10-05', 'amount' => '350.50', 'category' => 'transport']);
    Expense::factory()->voided()->create(['spent_on' => '2026-10-06', 'amount' => '120.25', 'category' => 'transport']);
    Expense::factory()->create(['spent_on' => '2026-10-31', 'amount' => '600.00', 'category' => 'salaries']);
    Expense::factory()->create(['spent_on' => '2026-09-30', 'amount' => '77.00', 'category' => 'rent']);
});

test('D100 the live expenses of a range per category, in the list order, both days inclusive', function (): void {
    // rent 1500.00 (30 Sep is out), transport 350.50 (the voided 120.25 is not), salaries 600.00 on the last day
    expect(app(ExpenseTotalsQuery::class)->byCategory('2026-10-01', '2026-10-31'))->toBe([
        'rent' => '1500.00',
        'salaries' => '600.00',
        'transport' => '350.50',
    ]);
});

test('a range without expenses has none', function (): void {
    expect(app(ExpenseTotalsQuery::class)->byCategory('2026-11-01', '2026-11-30'))->toBe([]);
});
