<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/dashboard/customer-groups: `{ name, discount_percent, sort_order? }` (BR-PR12, V2 Task 11, D89). */
final class StoreCustomerGroupRequest extends FormRequest
{
    /**
     * The rules a group's fields follow, shared with the PATCH (which makes each one `sometimes`).
     *
     * @return array<string, list<string>>
     */
    public static function fieldRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'discount_percent' => ['required', 'decimal:0,2', 'min:0', 'max:90'],
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return self::fieldRules();
    }

    public function name(): string
    {
        return mb_trim($this->string('name')->value());
    }

    public function discountPercent(): string
    {
        return Money::normalize($this->string('discount_percent')->value());
    }

    public function sortOrder(): ?int
    {
        return $this->filled('sort_order') ? $this->integer('sort_order') : null;
    }
}
