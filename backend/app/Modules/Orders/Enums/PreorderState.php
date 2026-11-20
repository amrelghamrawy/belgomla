<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * BR-O14, D88: where a pre-order stands, derived from its status and `converted_at`: `open` (pending, something left to
 * convert, partly converted ones included), `converted` (everything went into normal orders) or `cancelled`.
 */
enum PreorderState: string
{
    case Open = 'open';
    case Converted = 'converted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'محجوز',
            self::Converted => 'تم التحويل إلى طلب',
            self::Cancelled => 'ملغي',
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
