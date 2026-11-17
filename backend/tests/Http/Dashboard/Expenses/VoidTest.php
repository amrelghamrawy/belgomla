<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Expenses\Models\Expense;

beforeEach(function (): void {
    $this->me = $this->actingAsOwner();
    $this->expense = $this->inTenant(fn () => Expense::factory()->create(['amount' => '120.25', 'category' => 'transport']));
    $this->void = fn (array $body, ?int $id = null) => $this->tenantJson('POST', '/api/dashboard/expenses/'.($id ?? $this->expense->id).'/void', $body);
});

test('D100 c a wrong expense is voided with a reason, kept, and audited', function (): void {
    $this->freezeSecond();

    ($this->void)(['reason' => ' مكرر '])
        ->assertOk()
        ->assertJsonPath('data.voided_at', now()->toIso8601String())
        ->assertJsonPath('data.void_reason', 'مكرر');

    $this->assertDatabaseHas('expenses', ['id' => $this->expense->id, 'amount' => '120.25', 'void_reason' => 'مكرر', 'voided_by' => $this->me->id]);
    expect($this->inTenant(fn () => AuditLog::query()->sole()->only(['action', 'entity_id', 'old_values'])))
        ->toBe(['action' => 'expense.voided', 'entity_id' => $this->expense->id, 'old_values' => ['voided_at' => null]]);
});

test('D100 c a second void is 409 already_voided', function (): void {
    ($this->void)(['reason' => 'مكرر'])->assertOk();

    ($this->void)(['reason' => 'مرة ثانية'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'already_voided')
        ->assertJsonPath('error.message', 'تم إلغاء هذا المصروف من قبل');
});

test('a void needs a reason', function (array $body): void {
    ($this->void)($body)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['reason']]]);
})->with([
    'missing' => [[]],
    'too long' => [['reason' => str_repeat('س', 301)]],
]);

test('BR-T04 voiding another tenant\'s expense is 404 and leaves it', function (): void {
    $other = $this->otherTenant();
    $foreign = $this->inTenant(fn () => Expense::factory()->create(), $other);

    ($this->void)(['reason' => 'x'], $foreign->id)->assertNotFound()->assertJsonPath('error.code', 'not_found');

    expect($this->inTenant(fn () => Expense::query()->findOrFail($foreign->id)->voided_at, $other))->toBeNull();
});
