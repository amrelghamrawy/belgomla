<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Data\AddressData;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/dashboard/customers/{id}: `{ name, addresses[]?, credit_limit?, payment_terms_days? }`. The phone cannot change;
 * a credit term left out stays, null clears it (BR-C05/C06). When `addresses` is
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
            'credit_limit' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'payment_terms_days' => ['sometimes', 'nullable', 'integer', 'between:0,365'],
        ];
    }

    public function name(): string
    {
        return mb_trim($this->string('name')->value());
    }

    /** BR-C05: the limit as "150.00", null = no limit; only meaningful when creditTermsSent() lists it. */
    public function creditLimit(): ?string
    {
        return $this->filled('credit_limit') ? Money::normalize($this->string('credit_limit')->value()) : null;
    }

    /** BR-C06: days after delivery an order is due, null = due on delivery. */
    public function paymentTermsDays(): ?int
    {
        return $this->filled('payment_terms_days') ? $this->integer('payment_terms_days') : null;
    }

    /**
     * Which credit terms the request sent, so a null clears one on purpose and a missing one keeps it.
     *
     * @return list<string>
     */
    public function creditTermsSent(): array
    {
        return array_values(array_filter(['credit_limit', 'payment_terms_days'], $this->has(...)));
    }

    /**
     * @return list<AddressData>|null null when not sent: the addresses stay as they are
     */
    public function addresses(): ?array
    {
        return $this->has('addresses') ? StoreCustomerRequest::addressRows($this->validated('addresses')) : null;
    }
}
