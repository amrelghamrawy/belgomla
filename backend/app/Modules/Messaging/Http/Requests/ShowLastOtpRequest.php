<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Requests;

use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** GET /api/_test/last-otp?phone= (D62, local/testing only). */
final class ShowLastOtpRequest extends FormRequest
{
    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:30'],
        ];
    }

    public function phone(): string
    {
        return PhoneNumber::toE164($this->string('phone')->value()) ?? $this->string('phone')->value();
    }
}
