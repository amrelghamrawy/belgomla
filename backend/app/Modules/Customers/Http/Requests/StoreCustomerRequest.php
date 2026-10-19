<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Rules\EgyptianPhone;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/dashboard/customers: `{ name, phone, addresses[]?, credit_limit?, payment_terms_days? }` → status `invited` (BR-CU08, BR-C05/C06). */
final class StoreCustomerRequest extends FormRequest
{
    public const int MAX_ADDRESSES = 10;

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
