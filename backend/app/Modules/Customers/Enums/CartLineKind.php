<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

/**
 * BR-CU06, BR-O14: a cart line to order now, or to pre-order (a Coming Soon product with pre-orders on, D88). The two
 * live side by side in the one cart and are confirmed separately: checkout takes the order lines, the pre-order
 * confirmation the pre-order lines.
 */
enum CartLineKind: string
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
