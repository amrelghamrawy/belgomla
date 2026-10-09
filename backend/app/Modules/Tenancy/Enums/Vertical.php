<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

/**
 * The vertical template applied at tenant creation (BR-P09).
 */
enum Vertical: string
{
    case SchoolBooks = 'school-books';
    case Stationery = 'stationery';
    case Hardware = 'hardware';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::SchoolBooks => 'كتب دراسية',
            self::Stationery => 'أدوات مكتبية',
            self::Hardware => 'خردوات',
            self::None => 'بدون قالب',
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
