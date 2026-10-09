<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * BR-P04. Transitions (07-domain-model.md §11): active ⇄ inactive, coming_soon → active, coming_soon → inactive.
 */
enum ProductStatus: string
{
    case Active = 'active';
    case ComingSoon = 'coming_soon';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::ComingSoon => 'قريبًا',
            self::Inactive => 'غير نشط',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Active => $target === self::Inactive,
            self::Inactive => $target === self::Active,
            self::ComingSoon => $target === self::Active || $target === self::Inactive,
        };
    }

    public function isVisibleOnStore(): bool
    {
        return $this !== self::Inactive;
    }

    /**
     * @return list<self>
     */
    public static function visibleOnStore(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isVisibleOnStore()));
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
