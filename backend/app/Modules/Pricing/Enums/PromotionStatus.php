<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Enums;

/**
 * BR-PR15 (V2 Task 12, D90): where a promotion is in its life. A `draft` is saved but never live; `scheduled` waits for
 * its start; `active` is running; `ended` passed its end; `cancelled` was stopped by the merchant. `pricing:promotions-tick`
 * moves scheduled → active → ended every minute; prices read the times themselves (D90 c).
 */
enum PromotionStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Scheduled => 'مجدول',
            self::Active => 'جارٍ',
            self::Ended => 'انتهى',
            self::Cancelled => 'ملغي',
        };
    }

    /** D90 f: a draft or a scheduled promotion can still be edited (before its start). */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Scheduled;
    }

    /** D90 f: anything that has not ended or been cancelled already can be cancelled. */
    public function canBeCancelled(): bool
    {
        return $this !== self::Ended && $this !== self::Cancelled;
    }

    /** D90 c: the stored statuses a live promotion can have (its times decide). */
    public function mayBeLive(): bool
    {
        return $this === self::Scheduled || $this === self::Active;
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
