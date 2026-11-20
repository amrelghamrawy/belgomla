<?php

declare(strict_types=1);

namespace App\Modules\Collections\Enums;

/**
 * BR-C02, BR-R04: money received from the customer (`payment`) or paid back to them after a return (`refund`). A
 * refund keeps a positive amount and lowers the collected figure.
 */
enum CollectionKind: string
{
    case Payment = 'payment';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'تحصيل',
            self::Refund => 'رد',
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
