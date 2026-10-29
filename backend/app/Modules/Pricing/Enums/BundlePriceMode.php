<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Enums;

/**
 * BR-P15 (V2 Task 15, D93): how a bundle is priced: a fixed price, or the sum of its components' base prices less a %.
 */
enum BundlePriceMode: string
{
    case Fixed = 'fixed';
    case PercentOffSum = 'percent_off_sum';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'سعر ثابت',
            self::PercentOffSum => 'خصم من مجموع الأصناف',
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
