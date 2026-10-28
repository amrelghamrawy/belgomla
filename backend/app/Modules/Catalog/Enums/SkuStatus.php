<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * BR-P14 (V2 Task 14): an inactive SKU (variant) is hidden from the storefront and cannot be ordered, while the product
 * and its other variants stay on sale. A variant removed from the product form that has history is set inactive (BR-P05).
 */
enum SkuStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Inactive => 'غير نشط',
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
