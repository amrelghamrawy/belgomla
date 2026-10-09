<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/** BR-C03: derived from the collected amount against the order total. Computed by Collections (Task 7). */
enum CollectionStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyCollected = 'partially_collected';
    case Collected = 'collected';

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
