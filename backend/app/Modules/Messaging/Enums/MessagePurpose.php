<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Enums;

/**
 * BR-N01: WhatsApp and SMS carry OTPs, customer invitations and, from V2 Task 03, order status updates (BR-N03,
 * WhatsApp only, D80); `system_alert` is for platform notices to a merchant's own phone.
 */
enum MessagePurpose: string
{
    case Otp = 'otp';
    case Invitation = 'invitation';
    case SystemAlert = 'system_alert';
    case OrderStatus = 'order_status';

    public function label(): string
    {
        return match ($this) {
            self::Otp => 'رمز تحقق',
            self::Invitation => 'دعوة عميل',
            self::SystemAlert => 'تنبيه من المنصة',
            self::OrderStatus => 'تحديث طلب',
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
