<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Enums;

/**
 * BR-CU07: WhatsApp first, SMS as the fallback.
 */
enum Channel: string
{
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'واتساب',
            self::Sms => 'رسالة نصية',
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
