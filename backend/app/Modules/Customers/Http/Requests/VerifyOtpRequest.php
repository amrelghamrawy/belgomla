<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Customers\Rules\EgyptianPhone;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/** POST /api/store/auth/verify-otp: `{ phone, code, purpose }` (BR-CU07). */
final class VerifyOtpRequest extends FormRequest
{
    /**
     * @return array<string, list<string|ValidationRule|Enum>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:30', new EgyptianPhone],
            'code' => ['required', 'string', 'digits:6'],
            'purpose' => ['required', 'string', new Enum(OtpPurpose::class)],
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

    public function purpose(): OtpPurpose
    {
        return $this->enum('purpose', OtpPurpose::class) ?? OtpPurpose::Register;
    }
}
