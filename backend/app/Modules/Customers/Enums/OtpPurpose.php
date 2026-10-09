<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

/** BR-CU04 / BR-CU07: what an OTP proves. */
enum OtpPurpose: string
{
    case Register = 'register';
    case PasswordReset = 'password_reset';

    public function label(): string
    {
        return match ($this) {
            self::Register => 'تأكيد رقم الهاتف',
            self::PasswordReset => 'استعادة كلمة المرور',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function toArray(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
