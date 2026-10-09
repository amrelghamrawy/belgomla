<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/** BR-P10. */
enum RelationType: string
{
    case RevisionOf = 'revision_of';
    case Companion = 'companion';

    public function label(): string
    {
        return match ($this) {
            self::RevisionOf => 'مراجعة نهائية لـ',
            self::Companion => 'يُباع مع',
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
