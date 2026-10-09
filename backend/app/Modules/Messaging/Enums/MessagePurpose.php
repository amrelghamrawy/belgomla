<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Enums;

/**
 * BR-N01: WhatsApp and SMS carry only OTPs and customer invitations in V1; `system_alert` is for
 * platform notices to a merchant's own phone.
 */
enum MessagePurpose: string
{
    case Otp = 'otp';
    case Invitation = 'invitation';
    case SystemAlert = 'system_alert';

    public function label(): string
    {
        return match ($this) {
            self::Otp => 'رمز تحقق',
            self::Invitation => 'دعوة عميل',
            self::SystemAlert => 'تنبيه من المنصة',
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
