<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * Soft-removal status shared by categories, units, field definitions and field options.
 * The four tables use the identical pair, so one enum replaces four (Task 1 decision).
 */
enum ArchiveStatus: string
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
