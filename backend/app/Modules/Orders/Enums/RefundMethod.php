<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * BR-R04, BR-C07: how a return's over-collection goes back to the customer: cash in hand, or credit kept for their
 * next orders (V2 Task 02).
 */
enum RefundMethod: string
{
    case Cash = 'cash';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'رد نقدي',
            self::Credit => 'رصيد للعميل',
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
