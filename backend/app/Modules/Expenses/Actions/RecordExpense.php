<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Expenses\Data\ExpenseData;
use App\Modules\Expenses\Models\Expense;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/dashboard/expenses (D100): records what the shop spent, audited `expense.recorded`. Retries are made safe
 * by the `idempotent` route middleware (D55). Owns its transaction.
 */
final readonly class RecordExpense
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(ExpenseData $data, ?int $actorId = null): Expense
    {
        return DB::transaction(function () use ($data, $actorId): Expense {
            $expense = Expense::query()->create([
                'spent_on' => $data->spentOn,
                'amount' => $data->amount,
                'category' => $data->category,
                'note' => $data->note,
                'created_by' => $actorId,
            ]);

            $this->audit->log('expense.recorded', $expense, null, [
                'spent_on' => $data->spentOn,
                'amount' => $data->amount,
                'category' => $data->category->value,
                'note' => $data->note,
            ]);

            return $expense->refresh();
        });
    }
}
