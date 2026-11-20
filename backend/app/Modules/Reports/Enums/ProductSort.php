<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

/** How the product performance report is ordered (V2 Task 16): most net revenue, most base units, highest return rate. */
enum ProductSort: string
{
    case Revenue = 'revenue';
    case Quantity = 'quantity';
    case ReturnRate = 'return_rate';

    public function label(): string
    {
        return match ($this) {
            self::Revenue => 'الإيراد',
            self::Quantity => 'الكمية',
            self::ReturnRate => 'نسبة المرتجع',
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
