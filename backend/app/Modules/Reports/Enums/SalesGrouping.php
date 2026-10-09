<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

/** The bucket size of the sales report (D5 a); weeks start on Saturday (D58). */
enum SalesGrouping: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'يومي',
            self::Week => 'أسبوعي',
            self::Month => 'شهري',
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
