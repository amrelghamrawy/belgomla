<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

/** The two views of the customer patterns report (V2 Task 16): top customers by net revenue, or dormant ones. */
enum CustomerView: string
{
    case Top = 'top';
    case Dormant = 'dormant';

    public function label(): string
    {
        return match ($this) {
            self::Top => 'أفضل العملاء',
            self::Dormant => 'العملاء الخاملون',
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
