<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * The section a root category belongs to (D68). Children and products inherit it. It decides what the
 * SKU's optional reference price means: the printed cover price for books, the retail price otherwise
 * (BR-PR10), and which custom fields apply (BR-P08, D69).
 */
enum CategoryKind: string
{
    case Books = 'books';
    case Stationery = 'stationery';
    case Toys = 'toys';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Books => 'كتب خارجية',
            self::Stationery => 'أدوات دراسية',
            self::Toys => 'ألعاب',
            self::General => 'عام',
        };
    }

    /** What `cover_price` is called for products of this kind. */
    public function referencePriceLabel(): string
    {
        return $this === self::Books ? 'سعر الغلاف' : 'سعر القطاعي';
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
