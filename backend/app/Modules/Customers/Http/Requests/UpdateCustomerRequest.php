<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Enums\Permission;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PUT /api/dashboard/customers/{id}: `{ name, addresses[]?, credit_limit?, payment_terms_days? }`. The phone cannot change;
 * a credit term left out stays, null clears it (BR-C05/C06). When `addresses` is
 * sent it is the full list: rows with an `id` are edited, rows without one are added, the rest archived (D54).
 * D82: changing the name or the addresses needs `customers.manage`, changing a credit term `customers.credit`
 * (an accountant sends the name unchanged and no addresses), changing the group (`customer_group_id`, null = none)
 * `pricing.customers` (BR-PR12, V2 Task 11).
 */
final class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        if (! $customer instanceof Customer) {
            return true;
        }

        $profileChanged = $this->has('addresses') || ($this->has('name') && $this->name() !== $customer->name);
        $creditChanged = (in_array('credit_limit', $this->creditTermsSent(), true) && $this->creditLimit() !== $customer->credit_limit)
            || (in_array('payment_terms_days', $this->creditTermsSent(), true) && $this->paymentTermsDays() !== $customer->payment_terms_days);

        $groupChanged = $this->groupSent() && $this->groupId() !== $customer->customer_group_id;

        return (! $profileChanged || Gate::allows(Permission::CustomersManage->value))
            && (! $creditChanged || Gate::allows(Permission::CustomersCredit->value))
            && (! $groupChanged || Gate::allows(Permission::PricingCustomers->value));
    }

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
            'customer_group_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function name(): string
    {
        return mb_trim($this->string('name')->value());
    }

    /** BR-PR12 (V2 Task 11): whether `customer_group_id` was sent, so null takes the customer out of their group on purpose. */
    public function groupSent(): bool
    {
        return $this->has('customer_group_id');
    }

    public function groupId(): ?int
    {
        return $this->filled('customer_group_id') ? $this->integer('customer_group_id') : null;
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
