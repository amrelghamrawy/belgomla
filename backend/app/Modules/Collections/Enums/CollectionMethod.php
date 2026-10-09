<?php

declare(strict_types=1);

namespace App\Modules\Collections\Enums;

/** BR-C01, BR-C02: no online payment in V1, so every collection is cash. */
enum CollectionMethod: string
{
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'نقدي',
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
