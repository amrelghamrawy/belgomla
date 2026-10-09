<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Data\AddressData;
use App\Modules\Customers\Rules\EgyptianPhone;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/dashboard/customers: `{ name, phone, addresses[]? }` → status `invited` (BR-CU08). */
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
        ];
    }

    public function name(): string
    {
        return mb_trim($this->string('name')->value());
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
