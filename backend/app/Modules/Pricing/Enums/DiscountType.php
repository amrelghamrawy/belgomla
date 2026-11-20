<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Enums;

/**
 * BR-PR08 (V2 Task 08, D85): how a manual discount is given, on one order line (per unit) or on the whole order:
 * an amount off in EGP, or a percent off.
 */
enum DiscountType: string
{
    case Amount = 'amount';
    case Percent = 'percent';

    public function label(): string
    {
        return match ($this) {
            self::Amount => 'مبلغ',
            self::Percent => 'نسبة',
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
