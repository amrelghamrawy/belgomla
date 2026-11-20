<?php

declare(strict_types=1);

use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Modules\Expenses\Http\Resources\ExpenseResource;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $expense = Expense::factory()->create()->fresh();

    expect(array_keys($expense?->toArray() ?? []))->toBe(['id', 'tenant_id', 'spent_on', 'amount', 'category', 'note', 'created_by', 'voided_at', 'void_reason', 'voided_by', 'created_at', 'updated_at']);
});

test('casts: a business day, money, the category enum', function (): void {
    $expense = Expense::factory()->create(['spent_on' => '2026-10-09', 'amount' => 12.5, 'category' => 'fees'])->fresh();

    expect($expense?->spent_on)->toBeInstanceOf(CarbonImmutable::class)
        ->and($expense?->spent_on->toDateString())->toBe('2026-10-09')
        ->and($expense?->amount)->toBe('12.50')
        ->and($expense?->category)->toBe(ExpenseCategory::Fees);
});

test('D100 b every category has a label, only other needs a note', function (): void {
    expect(array_keys(ExpenseCategory::toArray()))->toBe(['rent', 'salaries', 'transport', 'utilities', 'maintenance', 'packaging', 'marketing', 'fees', 'other'])
        ->and(array_filter(ExpenseCategory::toArray(), fn (string $label): bool => $label === ''))->toBe([])
        ->and(array_values(array_filter(ExpenseCategory::cases(), fn (ExpenseCategory $category): bool => $category->needsNote())))->toBe([ExpenseCategory::Other]);
});

test('the expense resource key set', function (): void {
    $expense = Expense::factory()->voided('مكرر')->create(['spent_on' => '2026-10-09', 'note' => 'x'])->fresh();

    expect(array_keys(rendered(new ExpenseResource($expense), dashboardRequest('/api/dashboard/expenses'))))
        ->toBe(['id', 'spent_on', 'amount', 'category', 'category_label', 'note', 'created_by', 'created_at', 'voided_at', 'void_reason']);
});
