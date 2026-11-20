<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * What one row of a catalog import does (V2 Task 06): creates a product (its SKU code is new), updates the one with
 * that code, or is skipped because it has errors (nothing of it is written).
 */
enum ImportRowAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Skip = 'skip';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'منتج جديد',
            self::Update => 'تحديث',
            self::Skip => 'تم التخطي',
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
