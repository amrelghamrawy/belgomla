<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Rules\EgyptianPhone;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/store/auth/reset-password: `{ phone, code, password }` (BR-CU04, D50). */
final class ResetPasswordRequest extends FormRequest
{
    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:30', new EgyptianPhone],
            'code' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'string', 'min:'.config()->integer('customers.password_min'), 'max:200'],
        ];
    }

    public function phone(): string
    {
        return PhoneNumber::toE164($this->string('phone')->value()) ?? '';
    }

    public function code(): string
    {
        return $this->string('code')->value();
    }

    public function password(): string
    {
        return $this->string('password')->value();
    }
}
