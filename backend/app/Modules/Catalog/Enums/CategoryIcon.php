<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * The icon a merchant picks for a category tile on the storefront (D73). Values are the frontend's icon keys
 * (lucide names). Not checked in the database: the set grows with the frontend, the request validates it.
 */
enum CategoryIcon: string
{
    case Books = 'book-open';
    case Library = 'library';
    case School = 'graduation-cap';
    case Notebook = 'notebook-pen';
    case Pencil = 'pencil';
    case Pen = 'pen';
    case Highlighter = 'highlighter';
    case Eraser = 'eraser';
    case Ruler = 'ruler';
    case Scissors = 'scissors';
    case Palette = 'palette';
    case Brush = 'brush';
    case Calculator = 'calculator';
    case Backpack = 'backpack';
    case Folder = 'folder';
    case Paperclip = 'paperclip';
    case Paper = 'file-text';
    case Printer = 'printer';
    case Science = 'flask-conical';
    case Globe = 'globe';
    case Languages = 'languages';
    case Math = 'sigma';
    case Puzzle = 'puzzle';
    case Games = 'gamepad-2';
    case Shapes = 'shapes';
    case Baby = 'baby';
    case Gift = 'gift';
    case Clothes = 'shirt';
    case Electronics = 'laptop';
    case Package = 'package';

    public function label(): string
    {
        return match ($this) {
            self::Books => 'كتب',
            self::Library => 'مكتبة',
            self::School => 'مدرسة',
            self::Notebook => 'كراسات',
            self::Pencil => 'أقلام رصاص',
            self::Pen => 'أقلام',
            self::Highlighter => 'هايلايتر',
            self::Eraser => 'أساتيك',
            self::Ruler => 'مساطر',
            self::Scissors => 'مقصات',
            self::Palette => 'ألوان',
            self::Brush => 'فرش رسم',
            self::Calculator => 'آلات حاسبة',
            self::Backpack => 'شنط',
            self::Folder => 'حافظات',
            self::Paperclip => 'مستلزمات مكتبية',
            self::Paper => 'ورق',
            self::Printer => 'طباعة',
            self::Science => 'علوم',
            self::Globe => 'جغرافيا',
            self::Languages => 'لغات',
            self::Math => 'رياضيات',
            self::Puzzle => 'بازل',
            self::Games => 'ألعاب',
            self::Shapes => 'ألعاب تعليمية',
            self::Baby => 'أطفال',
            self::Gift => 'هدايا',
            self::Clothes => 'ملابس',
            self::Electronics => 'إلكترونيات',
            self::Package => 'عام',
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
