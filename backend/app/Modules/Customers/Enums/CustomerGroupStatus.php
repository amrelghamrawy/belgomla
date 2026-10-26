<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

/** BR-PR12, D89: an archived customer group stops applying its discount; its customers keep the group id. */
enum CustomerGroupStatus: string
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
