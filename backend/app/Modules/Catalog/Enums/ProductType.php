<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * BR-P15 (V2 Task 15, D93): a simple product has its own SKUs and stock; a bundle is one SKU sold in one unit, made of
 * component SKU units with fixed quantities, with no stock of its own (its availability is derived, BR-INV02).
 */
enum ProductType: string
{
    case Simple = 'simple';
    case Bundle = 'bundle';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'منتج',
            self::Bundle => 'باقة',
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
