<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Expenses\Models\Expense;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    // 22:30 UTC on 10 October is 01:30 on the 11th in Cairo (UTC+3 until the last Thursday of October, D60)
    $this->travelTo(CarbonImmutable::parse('2026-10-10 22:30:00', 'UTC'));
    $this->me = $this->actingAsOwner();
    $this->record = fn (array $body, array $headers = []) => $this->tenantJson('POST', '/api/dashboard/expenses', $body, headers: $headers);
});

test('D100 the merchant records an expense and gets it back', function (): void {
    ($this->record)(['spent_on' => '2026-10-11', 'amount' => '1500', 'category' => 'rent', 'note' => '  شهر أكتوبر  '])
        ->assertCreated()
        ->assertJsonPath('data.spent_on', '2026-10-11')
        ->assertJsonPath('data.amount', '1500.00')
        ->assertJsonPath('data.category', 'rent')
        ->assertJsonPath('data.category_label', 'إيجار')
        ->assertJsonPath('data.note', 'شهر أكتوبر')
        ->assertJsonPath('data.created_by', $this->me->id)
        ->assertJsonPath('data.voided_at', null);

    $this->assertDatabaseHas('expenses', ['tenant_id' => $this->tenant->id, 'amount' => '1500.00', 'category' => 'rent', 'created_by' => $this->me->id]);
});

test('BR-L recording is audited with what was recorded', function (): void {
    ($this->record)(['spent_on' => '2026-10-11', 'amount' => '350.5', 'category' => 'transport'])->assertCreated();

    $audit = $this->inTenant(fn () => AuditLog::query()->sole());

    expect($audit->only(['action', 'actor_id', 'old_values']))->toBe(['action' => 'expense.recorded', 'actor_id' => $this->me->id, 'old_values' => null])
        ->and($audit->new_values)->toEqual(['spent_on' => '2026-10-11', 'amount' => '350.50', 'category' => 'transport', 'note' => null]);
});

test('D100 a the amount boundaries and today in the store zone are accepted', function (array $body): void {
    ($this->record)(['category' => 'fees', ...$body])->assertCreated();
})->with([
    'the smallest amount, 0.01' => [['spent_on' => '2026-10-11', 'amount' => '0.01']],
    'the largest amount, 10,000,000.00' => [['spent_on' => '2026-10-11', 'amount' => '10000000.00']],
    'today in Cairo is already the 11th' => [['spent_on' => '2026-10-11', 'amount' => '5']],
]);

test('D100 a expense validation', function (array $body, string $field): void {
    ($this->record)($body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing day' => [['amount' => '5', 'category' => 'rent'], 'spent_on'],
    'a day after today in Cairo' => [['spent_on' => '2026-10-12', 'amount' => '5', 'category' => 'rent'], 'spent_on'],
    'not a day' => [['spent_on' => '11/10/2026', 'amount' => '5', 'category' => 'rent'], 'spent_on'],
    'zero amount' => [['spent_on' => '2026-10-10', 'amount' => '0.00', 'category' => 'rent'], 'amount'],
    'negative amount' => [['spent_on' => '2026-10-10', 'amount' => '-1', 'category' => 'rent'], 'amount'],
    'over the ceiling by a piaster' => [['spent_on' => '2026-10-10', 'amount' => '10000000.01', 'category' => 'rent'], 'amount'],
    'three decimals' => [['spent_on' => '2026-10-10', 'amount' => '1.005', 'category' => 'rent'], 'amount'],
    'unknown category' => [['spent_on' => '2026-10-10', 'amount' => '5', 'category' => 'purchases'], 'category'],
    'other without a note' => [['spent_on' => '2026-10-10', 'amount' => '5', 'category' => 'other'], 'note'],
    'note too long' => [['spent_on' => '2026-10-10', 'amount' => '5', 'category' => 'rent', 'note' => str_repeat('س', 201)], 'note'],
]);

test('D55 the same Idempotency-Key twice records the expense once', function (): void {
    $body = ['spent_on' => '2026-10-10', 'amount' => '600', 'category' => 'salaries'];

    ($this->record)($body, ['Idempotency-Key' => 'expense-1'])->assertCreated();
    ($this->record)($body, ['Idempotency-Key' => 'expense-1'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($this->inTenant(fn () => Expense::query()->count()))->toBe(1);
});

test('D100 b the categories are listed in order with their labels and which needs a note', function (): void {
    $this->tenantJson('GET', '/api/dashboard/expenses/categories')
        ->assertOk()
        ->assertJsonCount(9, 'data')
        ->assertJsonPath('data.0', ['code' => 'rent', 'label' => 'إيجار', 'needs_note' => false])
        ->assertJsonPath('data.8', ['code' => 'other', 'label' => 'أخرى', 'needs_note' => true]);
});

test('D100 i expenses need expenses.manage', function (string $role, int $status): void {
    $this->actingAsOwner($this->makeOwner(attributes: ['role' => $role, 'email' => "{$role}@alnoor.test"]));

    $this->tenantJson('GET', '/api/dashboard/expenses')->assertStatus($status);
})->with([
    'owner' => ['owner', 200],
    'manager' => ['manager', 200],
    'accountant' => ['accountant', 200],
    'sales' => ['sales', 403],
    'warehouse' => ['warehouse', 403],
]);
