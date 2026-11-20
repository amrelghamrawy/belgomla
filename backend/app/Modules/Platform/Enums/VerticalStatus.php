<?php

declare(strict_types=1);

namespace App\Modules\Platform\Enums;

/**
 * Status of a vertical, a vertical section and a field template (D108 e, D110). Archived = not offered to new
 * tenants and not copied any more; what tenants already copied stays theirs.
 */
enum VerticalStatus: string
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
