<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/** Custom product field types (BR-P08, D14). */
enum FieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Select = 'select';
    case Boolean = 'boolean';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'نص',
            self::Number => 'رقم',
            self::Select => 'اختيار من قائمة',
            self::Boolean => 'نعم / لا',
        };
    }

    /** Only select and boolean fields can be storefront facets (BR-P08). */
    public function isFilterable(): bool
    {
        return $this === self::Select || $this === self::Boolean;
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
