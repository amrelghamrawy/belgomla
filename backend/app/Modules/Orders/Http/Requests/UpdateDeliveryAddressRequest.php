<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Customers\Rules\EgyptianPhone;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/dashboard/orders/{number}/delivery-address (BR-O10, D9): the address fields of a customer address
 * (BR-CU03), plus an optional recipient `name` and `phone`; when left out they stay as they are.
 */
final class UpdateDeliveryAddressRequest extends FormRequest
{
    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:50'],
            'governorate' => ['required', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:80'],
            'address_line' => ['required', 'string', 'max:300'],
            'landmark' => ['nullable', 'string', 'max:200'],
            'name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', new EgyptianPhone],
        ];
    }

    /** A trimmed text field, null when not sent or blank. */
    public function optionalText(string $key): ?string
    {
        $value = mb_trim($this->string($key)->value());

        return $value === '' ? null : $value;
    }

    public function phone(): ?string
    {
        $phone = $this->optionalText('phone');

        return $phone === null ? null : PhoneNumber::toE164($phone);
    }
}
