<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Resources;

use App\Modules\Expenses\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One expense (D100): the business day, amount, category with its label, note, who recorded it, and the void.
 *
 * @property-read Expense $resource
 */
final class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $expense = $this->resource;

        return [
            'id' => $expense->id,
            'spent_on' => $expense->spent_on->toDateString(),
            'amount' => $expense->amount,
            'category' => $expense->category->value,
            'category_label' => $expense->category->label(),
            'note' => $expense->note,
            'created_by' => $expense->created_by,
            'created_at' => $expense->created_at->toIso8601String(),
            'voided_at' => $expense->voided_at?->toIso8601String(),
            'void_reason' => $expense->void_reason,
        ];
    }
}
