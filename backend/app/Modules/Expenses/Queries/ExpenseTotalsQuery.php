<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Queries;

use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Modules\Expenses\Models\Expense;
use App\Support\Money;

/**
 * D100: the live expenses of a range of business days (both inclusive) per category, for the net profit report
 * (Reports reads it across modules, rules/02 §1). Voided expenses count nowhere (D100 c).
 */
final readonly class ExpenseTotalsQuery
{
    /**
     * Every category with an expense in the range, in the enum's order, and its total.
     *
     * @return array<string, string> category code => amount
     */
    public function byCategory(string $from, string $to): array
    {
        $rows = Expense::query()
            ->whereNull('voided_at')
            ->whereBetween('spent_on', [$from, $to])
            ->groupBy('category')
            ->selectRaw('category, sum(amount) as amount')
            ->toBase()
            ->get();
        $sums = [];

        foreach ($rows as $row) {
            /** @var object{category: string, amount: string} $row */
            $sums[$row->category] = Money::add('0.00', $row->amount);
        }

        $out = [];

        foreach (ExpenseCategory::cases() as $category) {
            if (isset($sums[$category->value])) {
                $out[$category->value] = $sums[$category->value];
            }
        }

        return $out;
    }
}
