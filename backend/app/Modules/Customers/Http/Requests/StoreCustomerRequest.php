<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Data\AddressData;
use App\Modules\Tenancy\Enums\Permission;
use App\Modules\Tenancy\Rules\EgyptianPhone;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/dashboard/customers: `{ name, phone, addresses[]?, credit_limit?, payment_terms_days?, customer_group_id? }` →
 * status `invited` (BR-CU08, BR-C05/C06). Putting the new customer in a group needs `pricing.customers` (BR-PR12).
 */
final class StoreCustomerRequest extends FormRequest
{
    public const int MAX_ADDRESSES = 10;

    public function authorize(): bool
    {
        return $this->groupId() === null || Gate::allows(Permission::PricingCustomers->value);
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', new EgyptianPhone],
            'addresses' => ['sometimes', 'array', 'max:'.self::MAX_ADDRESSES],
            'addresses.*' => ['array'],
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

    public function phone(): string
    {
        return PhoneNumber::toE164($this->string('phone')->value()) ?? '';
    }

    /** The dashboard user creating the customer (`customers.created_by`). */
    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }

    /**
     * @return list<AddressData>
     */
    public function addresses(): array
    {
        return self::addressRows($this->validated('addresses', []));
    }

    /**
     * @return list<AddressData>
     */
    public static function addressRows(mixed $rows): array
    {
        $addresses = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $address = [];

            foreach (is_array($row) ? $row : [] as $key => $value) {
                $address[(string) $key] = $value;
            }

            $addresses[] = SaveAddressRequest::toData($address);
        }

        return $addresses;
    }
}
