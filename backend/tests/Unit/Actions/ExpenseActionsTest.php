<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Expenses\Actions\RecordExpense;
use App\Modules\Expenses\Actions\VoidExpense;
use App\Modules\Expenses\Data\ExpenseData;
use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Modules\Expenses\Exceptions\ExpenseAlreadyVoided;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('D100 recording writes the expense and the audit in one transaction', function (): void {
    $user = $this->makeOwner();

    $expense = app(RecordExpense::class)->handle(new ExpenseData('2026-10-10', '600.00', ExpenseCategory::Salaries, 'عامل التحميل'), $user->id);

    expect($expense->only(['amount', 'category', 'note', 'created_by']))->toBe(['amount' => '600.00', 'category' => ExpenseCategory::Salaries, 'note' => 'عامل التحميل', 'created_by' => $user->id])
        ->and($expense->spent_on->toDateString())->toBe('2026-10-10')
        ->and(AuditLog::query()->sole()->action)->toBe('expense.recorded');
});

test('D100 a the database refuses an other expense without a note, and nothing is audited', function (): void {
    expect(fn () => app(RecordExpense::class)->handle(new ExpenseData('2026-10-10', '5.00', ExpenseCategory::Other)))->toThrow(QueryException::class);

    expect(Expense::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('D100 c voiding twice is refused with when it was voided', function (): void {
    $expense = Expense::factory()->create();
    app(VoidExpense::class)->handle($expense, 'مكرر');

    expect(fn () => app(VoidExpense::class)->handle($expense, 'مرة ثانية'))->toThrow(function (ExpenseAlreadyVoided $e) use ($expense): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([409, 'already_voided', ['voided_at' => $expense->fresh()?->voided_at?->toIso8601String()]]);
    });
});
