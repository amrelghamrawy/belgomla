<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Data;

use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Modules\Expenses\Http\Requests\ListExpensesRequest;

/** The expense list's filters: business days (both inclusive), a category, and live or voided (null = both). */
final readonly class ExpenseFiltersData
{
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public ?ExpenseCategory $category = null,
        public ?bool $voided = null,
    ) {}

    public static function fromRequest(ListExpensesRequest $request): self
    {
        return new self($request->from(), $request->to(), $request->category(), $request->voided());
    }
}
