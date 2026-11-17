<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Enums;

/**
 * D100 b: what an expense was for, from one fixed list for every store. There is no "purchases": goods bought for sale
 * come in through «استلام بضاعة» (D96 b) and reach the profit as the cost of what is sold (D100 d).
 */
enum ExpenseCategory: string
{
    case Rent = 'rent';
    case Salaries = 'salaries';
    case Transport = 'transport';
    case Utilities = 'utilities';
    case Maintenance = 'maintenance';
    case Packaging = 'packaging';
    case Marketing = 'marketing';
    case Fees = 'fees';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Rent => 'إيجار',
            self::Salaries => 'رواتب وأجور',
            self::Transport => 'نقل وشحن',
            self::Utilities => 'كهرباء ومياه وإنترنت',
            self::Maintenance => 'صيانة',
            self::Packaging => 'تغليف ومستلزمات',
            self::Marketing => 'دعاية وتسويق',
            self::Fees => 'رسوم وضرائب',
            self::Other => 'أخرى',
        };
    }

    /** "Other" needs a note saying what it was. */
    public function needsNote(): bool
    {
        return $this === self::Other;
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
