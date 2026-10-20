<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * BR-O14, D88: a normal order, or a pre-order of a Coming Soon product. A pre-order stays `pending`, holds no stock and
 * is never confirmed: the merchant converts it into normal orders when the stock arrives.
 */
enum OrderKind: string
{
    case Order = 'order';
    case Preorder = 'preorder';

    public function label(): string
    {
        return match ($this) {
            self::Order => 'طلب',
            self::Preorder => 'حجز مسبق',
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
