<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * The file formats of the catalog import/export (V2 Task 06, D83): XLSX for people, CSV (UTF-8 with a BOM, keys
 * only in the header) for other tools. The price list is XLSX only.
 */
enum SpreadsheetFormat: string
{
    case Xlsx = 'xlsx';
    case Csv = 'csv';

    public function label(): string
    {
        return match ($this) {
            self::Xlsx => 'Excel',
            self::Csv => 'CSV',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Csv => 'text/csv; charset=UTF-8',
        };
    }

    /** The format of a stored or uploaded file, by its extension (`.txt` is how some browsers name a CSV). */
    public static function fromExtension(string $extension): ?self
    {
        return match (mb_strtolower($extension)) {
            'xlsx' => self::Xlsx,
            'csv', 'txt' => self::Csv,
            default => null,
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
