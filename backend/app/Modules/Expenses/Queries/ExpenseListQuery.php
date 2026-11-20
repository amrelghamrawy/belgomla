<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Queries;

use App\Modules\Expenses\Data\ExpenseFiltersData;
use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Modules\Expenses\Models\Expense;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * GET /api/dashboard/expenses (D100): the tenant's expenses, newest `spent_on` first (then newest recorded), filtered by
 * business days, category and live or voided; `liveTotal()` sums the live ones the same filters keep.
 */
final readonly class ExpenseListQuery
{
    /**
     * @return LengthAwarePaginator<int, Expense>
     */
    public function paginate(ExpenseFiltersData $filters, int $perPage): LengthAwarePaginator
    {
        return $this->builder($filters)->orderByDesc('spent_on')->orderByDesc('id')->paginate($perPage);
    }

    /** The live (not voided) total of what the filters keep, "0.00" when nothing. */
    public function liveTotal(ExpenseFiltersData $filters): string
    {
        $sum = $this->builder($filters)->whereNull('voided_at')->sum('amount');

        return Money::add('0.00', (string) $sum);
    }

    /**
     * @return Builder<Expense>
     */
    private function builder(ExpenseFiltersData $filters): Builder
    {
        return Expense::query()
            ->when($filters->from, fn (Builder $query, string $from): Builder => $query->where('spent_on', '>=', $from))
            ->when($filters->to, fn (Builder $query, string $to): Builder => $query->where('spent_on', '<=', $to))
            ->when($filters->category, fn (Builder $query, ExpenseCategory $category): Builder => $query->where('category', $category))
            ->when($filters->voided !== null, fn (Builder $query): Builder => $filters->voided === true ? $query->whereNotNull('voided_at') : $query->whereNull('voided_at'));
    }
}
