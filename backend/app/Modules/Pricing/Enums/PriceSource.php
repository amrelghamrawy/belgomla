<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Enums;

/**
 * BR-PR14 (V2 Task 11, D89): where a resolved unit price came from: the SKU's base price, one of its quantity tiers
 * (BR-PR04), the customer's group discount off the base price (BR-PR12) or the price negotiated for the customer on the
 * SKU (BR-PR13), or a live promotion (BR-PR15, V2 Task 12). Snapshotted on the order line; the storefront marks `group` and `customer` as «سعرك الخاص».
 */
enum PriceSource: string
{
    case Base = 'base';
    case Tier = 'tier';
    case Group = 'group';
    case Customer = 'customer';
    case Promotion = 'promotion';

    public function label(): string
    {
        return match ($this) {
            self::Base => 'السعر الأساسي',
            self::Tier => 'سعر الكمية',
            self::Group => 'سعر المستوى',
            self::Customer => 'سعر خاص بالعميل',
            self::Promotion => 'سعر العرض',
        };
    }

    /** The customer's own price (group or negotiated), shown with a «سعرك الخاص» badge. */
    public function isCustomerSpecific(): bool
    {
        return $this === self::Group || $this === self::Customer;
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
