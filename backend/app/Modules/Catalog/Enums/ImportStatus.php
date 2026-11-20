<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/** Where a catalog import (`imports` row, V2 Task 06) stands: queued → running → done | failed. */
enum ImportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'في الانتظار',
            self::Running => 'جارٍ الاستيراد',
            self::Done => 'تم',
            self::Failed => 'فشل',
        };
    }

    public function isFinished(): bool
    {
        return $this === self::Done || $this === self::Failed;
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
