<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Customers\Rules\EgyptianPhone;
use App\Modules\Messaging\Enums\Channel;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/** POST /api/store/auth/resend-otp: `{ phone, purpose, channel? }`; `channel=sms` is "send by SMS instead" (BR-CU07). */
final class ResendOtpRequest extends FormRequest
{
    /**
     * @return array<string, list<string|ValidationRule|Enum>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:30', new EgyptianPhone],
            'purpose' => ['required', 'string', new Enum(OtpPurpose::class)],
            'channel' => ['sometimes', 'string', new Enum(Channel::class)],
        ];
    }

    public function phone(): string
    {
        return PhoneNumber::toE164($this->string('phone')->value()) ?? '';
    }

    public function purpose(): OtpPurpose
    {
        return $this->enum('purpose', OtpPurpose::class) ?? OtpPurpose::Register;
    }

    public function channel(): Channel
    {
        return $this->enum('channel', Channel::class) ?? Channel::WhatsApp;
    }
}
