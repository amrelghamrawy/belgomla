<?php

declare(strict_types=1);

namespace App\Modules\Collections\Enums;

/** BR-C01, BR-C02: no online payment, so money is cash; from V2 Task 02 a collection can also draw on or create customer credit (BR-C07). */
enum CollectionMethod: string
{
    case Cash = 'cash';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'نقدي',
            self::Credit => 'رصيد العميل',
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
