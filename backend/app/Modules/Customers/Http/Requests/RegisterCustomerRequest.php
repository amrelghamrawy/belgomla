<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Rules\EgyptianPhone;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/store/auth/register: `{ name, phone, password }` (BR-CU04, D50). */
final class RegisterCustomerRequest extends FormRequest
{
    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', new EgyptianPhone],
            'password' => ['required', 'string', 'min:'.config()->integer('customers.password_min'), 'max:200'],
        ];
    }

    public function name(): string
    {
        return mb_trim($this->string('name')->value());
    }

    /** E.164, e.g. `+201012345678`. */
    public function phone(): string
    {
        return PhoneNumber::toE164($this->string('phone')->value()) ?? '';
    }

    public function password(): string
    {
        return $this->string('password')->value();
    }
}
