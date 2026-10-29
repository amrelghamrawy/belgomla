<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

/** The advanced reports of V2 Task 16 (D5 extended, D94), by their path under `/api/dashboard/reports/`. */
enum ReportName: string
{
    case SalesAnalytics = 'sales-analytics';
    case Products = 'products';
    case Customers = 'customers';
    case Inventory = 'inventory';
    case Collections = 'collections';

    public function label(): string
    {
        return match ($this) {
            self::SalesAnalytics => 'المبيعات',
            self::Products => 'المنتجات',
            self::Customers => 'العملاء',
            self::Inventory => 'المخزون',
            self::Collections => 'التحصيل',
        };
    }

    /** The file name of its export, without the extension (Latin, so every browser keeps it). */
    public function fileName(string $from, string $to): string
    {
        return "report-{$this->value}-{$from}-{$to}";
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
