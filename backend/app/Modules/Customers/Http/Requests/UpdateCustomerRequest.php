<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Data\AddressData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/dashboard/customers/{id}: `{ name, addresses[]? }`. The phone cannot change. When `addresses` is
 * sent it is the full list: rows with an `id` are edited, rows without one are added, the rest archived (D54).
 */
final class UpdateCustomerRequest extends FormRequest
{
    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'addresses' => ['sometimes', 'array', 'max:'.StoreCustomerRequest::MAX_ADDRESSES],
            'addresses.*' => ['array'],
            'addresses.*.id' => ['sometimes', 'integer', 'min:1'],
            ...SaveAddressRequest::fieldRules('addresses.*.'),
        ];
    }

    public function name(): string
    {
        return mb_trim($this->string('name')->value());
    }

    /**
     * @return list<AddressData>|null null when not sent: the addresses stay as they are
     */
    public function addresses(): ?array
    {
        return $this->has('addresses') ? StoreCustomerRequest::addressRows($this->validated('addresses')) : null;
    }
}
