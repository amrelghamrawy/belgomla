<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Requests;

use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Support\Http\PaginatedRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/** GET /api/dashboard/expenses: `?from=&to=&category=&voided=` (business days, both inclusive; voided 1 or 0). */
final class ListExpensesRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category' => ['sometimes', 'string', Rule::enum(ExpenseCategory::class)],
            'voided' => ['sometimes', 'boolean'],
        ];
    }

    public function from(): ?string
    {
        return $this->filled('from') ? $this->string('from')->value() : null;
    }

    public function to(): ?string
    {
        return $this->filled('to') ? $this->string('to')->value() : null;
    }

    public function category(): ?ExpenseCategory
    {
        return $this->filled('category') ? ExpenseCategory::from($this->string('category')->value()) : null;
    }

    public function voided(): ?bool
    {
        return $this->has('voided') ? $this->boolean('voided') : null;
    }
}
