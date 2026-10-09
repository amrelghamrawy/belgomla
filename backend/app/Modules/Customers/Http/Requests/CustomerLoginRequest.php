<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Rules\EgyptianPhone;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/store/auth/login: `{ phone, password }` (BR-CU04). */
final class CustomerLoginRequest extends FormRequest
{
    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:30', new EgyptianPhone],
            'password' => ['required', 'string', 'max:200'],
        ];
    }

    public function phone(): string
    {
        return PhoneNumber::toE164($this->string('phone')->value()) ?? '';
    }

    public function password(): string
    {
        return $this->string('password')->value();
    }
}
