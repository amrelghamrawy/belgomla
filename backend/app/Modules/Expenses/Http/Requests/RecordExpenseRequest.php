<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Requests;

use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * POST /api/dashboard/expenses (D100 a): `{ spent_on, amount, category, note? }`. `spent_on` is a business day not after
 * today in the store's zone (D60); `amount` 0.01..10,000,000.00; `note` ≤ 200, required for `other`.
 */
final class RecordExpenseRequest extends FormRequest
{
    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        $today = CarbonImmutable::now(config()->string('app.business_timezone'))->toDateString();

        return [
            'spent_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'amount' => ['required', 'decimal:0,2', 'min:0.01', 'max:10000000'],
            'category' => ['required', 'string', Rule::enum(ExpenseCategory::class)],
            'note' => ['nullable', 'string', 'max:200', 'required_if:category,'.ExpenseCategory::Other->value],
        ];
    }

    public function spentOn(): string
    {
        return $this->string('spent_on')->value();
    }

    public function amount(): string
    {
        return Money::normalize($this->string('amount')->value());
    }

    public function category(): ExpenseCategory
    {
        return ExpenseCategory::from($this->string('category')->value());
    }

    public function note(): ?string
    {
        $note = mb_trim($this->string('note')->value());

        return $note === '' ? null : $note;
    }

    /** The dashboard user recording it (expenses.created_by). */
    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
