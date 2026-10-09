<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/** BR-N02: what the merchant is told about in the dashboard. */
enum NotificationType: string
{
    case NewOrder = 'new_order';
    case CustomerCancelled = 'customer_cancelled';

    public function label(): string
    {
        return match ($this) {
            self::NewOrder => 'طلب جديد',
            self::CustomerCancelled => 'إلغاء من العميل',
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
