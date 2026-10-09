<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

/** BR-CU03: a deleted address is archived, so order snapshots and history stay readable. */
enum AddressStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Archived => 'مؤرشف',
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
