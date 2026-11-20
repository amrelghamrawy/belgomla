<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Expenses\Exceptions\ExpenseAlreadyVoided;
use App\Modules\Expenses\Models\Expense;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/dashboard/expenses/{id}/void (D100 c): a wrong expense is voided with a reason and entered again, never
 * edited or deleted. The row is locked, so two voids at once give one `409 already_voided`. Audited `expense.voided`.
 * Owns its transaction.
 */
final readonly class VoidExpense
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Expense $expense, string $reason, ?int $actorId = null): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $actorId): Expense {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($expense->voided_at !== null) {
                throw ExpenseAlreadyVoided::at($expense->voided_at);
            }

            $expense->forceFill(['voided_at' => now(), 'void_reason' => $reason, 'voided_by' => $actorId])->save();

            $this->audit->log('expense.voided', $expense, ['voided_at' => null], [
                'voided_at' => $expense->voided_at?->toIso8601String(),
                'void_reason' => $reason,
                'amount' => $expense->amount,
                'category' => $expense->category->value,
            ]);

            return $expense;
        });
    }
}
