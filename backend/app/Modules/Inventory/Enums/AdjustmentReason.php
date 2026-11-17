<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * D97 (BR-INV06): why the merchant changed On Hand by hand, from a fixed list, so losses can be added up by cause
 * (with the cost the adjustment moved at, BR-INV09). `other` needs a note. `import` is written only by the catalog
 * import (D95 e) and cannot be chosen through the API.
 */
enum AdjustmentReason: string
{
    case Stocktake = 'stocktake';
    case Damaged = 'damaged';
    case Expired = 'expired';
    case RecordingError = 'recording_error';
    case Found = 'found';
    case Other = 'other';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Stocktake => 'جرد',
            self::Damaged => 'كسر أو تلف',
            self::Expired => 'انتهاء صلاحية',
            self::RecordingError => 'تصحيح خطأ تسجيل',
            self::Found => 'بضاعة وُجدت',
            self::Other => 'أخرى',
            self::Import => 'استيراد',
        };
    }

    /** A note is required to say what "other" was. */
    public function needsNote(): bool
    {
        return $this === self::Other;
    }

    /**
     * The reasons a merchant may choose (every case but `import`).
     *
     * @return list<string>
     */
    public static function chosenByMerchant(): array
    {
        $codes = [];

        foreach (self::cases() as $reason) {
            if ($reason !== self::Import) {
                $codes[] = $reason->value;
            }
        }

        return $codes;
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
