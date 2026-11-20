<?php

declare(strict_types=1);

use App\Modules\Expenses\Models\Expense;

beforeEach(function (): void {
    $this->actingAsOwner();
    [$this->rent, $this->transport, $this->voided, $this->salaries] = $this->inTenant(fn (): array => [
        Expense::factory()->create(['spent_on' => '2026-10-01', 'amount' => '1500.00', 'category' => 'rent']),
        Expense::factory()->create(['spent_on' => '2026-10-05', 'amount' => '350.50', 'category' => 'transport']),
        Expense::factory()->voided()->create(['spent_on' => '2026-10-06', 'amount' => '120.25', 'category' => 'transport']),
        Expense::factory()->create(['spent_on' => '2026-10-09', 'amount' => '600.00', 'category' => 'salaries']),
    ]);
});

test('D100 the list is newest day first, voided rows marked, with the live total', function (): void {
    // 1500.00 + 350.50 + 600.00 = 2450.50; the voided 120.25 is listed but not counted
    $this->tenantJson('GET', '/api/dashboard/expenses')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$this->salaries->id, $this->voided->id, $this->transport->id, $this->rent->id])
        ->assertJsonPath('data.1.void_reason', 'تسجيل خاطئ')
        ->assertJsonPath('meta.total_amount', '2450.50')
        ->assertJsonStructure(['data' => ['*' => ['id', 'spent_on', 'amount', 'category', 'category_label', 'note', 'created_by', 'created_at', 'voided_at', 'void_reason']], 'links', 'meta' => ['current_page', 'total', 'total_amount']]);
});

test('D100 the filters narrow the rows and the total', function (string $query, array $ids, string $total): void {
    $this->tenantJson('GET', "/api/dashboard/expenses?{$query}")
        ->assertOk()
        ->assertJsonPath('data.*.id', array_map(fn (string $name): int => $this->{$name}->id, $ids))
        ->assertJsonPath('meta.total_amount', $total);
})->with([
    'a category: 350.50 live, 120.25 voided' => ['category=transport', ['voided', 'transport'], '350.50'],
    'a range, both days inclusive: 350.50 + 600.00' => ['from=2026-10-05&to=2026-10-09', ['salaries', 'voided', 'transport'], '950.50'],
    'voided only' => ['voided=1', ['voided'], '0.00'],
    'live only' => ['voided=0', ['salaries', 'transport', 'rent'], '2450.50'],
]);

test('expense list validation', function (string $query, string $field): void {
    $this->tenantJson('GET', "/api/dashboard/expenses?{$query}")
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'a range backwards' => ['from=2026-10-09&to=2026-10-01', 'to'],
    'unknown category' => ['category=purchases', 'category'],
    'not a day' => ['from=yesterday', 'from'],
]);

test('BR-T04 another tenant\'s expenses are never listed', function (): void {
    $this->inTenant(fn () => Expense::factory()->create(['amount' => '9999.00']), $this->otherTenant());

    $this->tenantJson('GET', '/api/dashboard/expenses')
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('meta.total_amount', '2450.50');
});
