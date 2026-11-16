<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * BR-INV06: what changed On Hand. `return` is a customer return (BR-R03, positive) or its void (BR-R06, negative);
 * `receive` is goods the merchant bought, at the price paid (BR-INV09).
 */
enum MovementType: string
{
    case Opening = 'opening';
    case Adjustment = 'adjustment';
    case Sale = 'sale';
    case Return = 'return';
    case Receive = 'receive';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'رصيد افتتاحي',
            self::Adjustment => 'تعديل يدوي',
            self::Sale => 'بيع',
            self::Return => 'مرتجع',
            self::Receive => 'استلام بضاعة',
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
