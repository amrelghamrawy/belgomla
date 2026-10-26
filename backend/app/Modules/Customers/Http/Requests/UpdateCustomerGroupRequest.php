<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Enums\CustomerGroupStatus;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/dashboard/customer-groups/{id}: any of `{ name, discount_percent, sort_order, status }` (BR-PR12, V2 Task 11);
 * a field left out stays. `status` only takes `active`, to bring an archived group back (archiving has its own endpoint).
 */
final class UpdateCustomerGroupRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (StoreCustomerGroupRequest::fieldRules() as $field => $fieldRules) {
            $rules[$field] = ['sometimes', ...array_values(array_filter($fieldRules, fn (string $rule): bool => $rule !== 'required' && $rule !== 'sometimes'))];
        }

        return [...$rules, 'status' => ['sometimes', 'string', 'in:'.CustomerGroupStatus::Active->value]];
    }

    public function name(): ?string
    {
        return $this->has('name') ? mb_trim($this->string('name')->value()) : null;
    }

    public function discountPercent(): ?string
    {
        return $this->has('discount_percent') ? Money::normalize($this->string('discount_percent')->value()) : null;
    }

    public function sortOrder(): ?int
    {
        return $this->has('sort_order') ? $this->integer('sort_order') : null;
    }

    public function status(): ?CustomerGroupStatus
    {
        return $this->enum('status', CustomerGroupStatus::class);
    }
}
