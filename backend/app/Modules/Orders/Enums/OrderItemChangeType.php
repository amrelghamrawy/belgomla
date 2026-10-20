<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * BR-O12, BR-O13: what happened to an order line after the order was placed: the merchant added it, removed it or
 * changed its quantity (EditOrderLines), or it was delivered short of what was ordered (partial delivery, D86).
 */
enum OrderItemChangeType: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Quantity = 'quantity';
    case DeliveredShort = 'delivered_short';

    public function label(): string
    {
        return match ($this) {
            self::Added => 'صنف مضاف',
            self::Removed => 'صنف محذوف',
            self::Quantity => 'تعديل الكمية',
            self::DeliveredShort => 'تسليم جزئي',
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
