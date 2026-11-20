<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Data;

use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Modules\Expenses\Http\Requests\RecordExpenseRequest;

/** D100 a: an expense to record: the business day, the amount (money), the category and the note. */
final readonly class ExpenseData
{
    public function __construct(
        public string $spentOn,
        public string $amount,
        public ExpenseCategory $category,
        public ?string $note = null,
    ) {}

    public static function fromRequest(RecordExpenseRequest $request): self
    {
        return new self($request->spentOn(), $request->amount(), $request->category(), $request->note());
    }
}
