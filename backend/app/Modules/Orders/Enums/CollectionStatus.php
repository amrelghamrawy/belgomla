<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Support\Money;

/** BR-C03: derived from the collected amount against the order total (Orders\Actions\ApplyCollectedAmount). */
enum CollectionStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyCollected = 'partially_collected';
    case Collected = 'collected';

    /** BR-C03: 0 → unpaid, below the total → partially collected, the total or more → collected. */
    public static function for(string $collected, string $total): self
    {
        return match (true) {
            Money::compare($collected, '0.00') <= 0 => self::Unpaid,
            Money::compare($collected, $total) < 0 => self::PartiallyCollected,
            default => self::Collected,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'غير محصل',
            self::PartiallyCollected => 'محصل جزئيًا',
            self::Collected => 'محصل',
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
