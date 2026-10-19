<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * BR-N02: what the merchant is told about in the dashboard; `whatsapp_dropped` is the store number logging out (D70),
 * `order_expired` a pending order the system cancelled (BR-INV07, D81).
 */
enum NotificationType: string
{
    case NewOrder = 'new_order';
    case CustomerCancelled = 'customer_cancelled';
    case WhatsappDropped = 'whatsapp_dropped';
    case OrderExpired = 'order_expired';

    public function label(): string
    {
        return match ($this) {
            self::NewOrder => 'طلب جديد',
            self::CustomerCancelled => 'إلغاء من العميل',
            self::WhatsappDropped => 'انقطع رقم واتساب المتجر',
            self::OrderExpired => 'إلغاء تلقائي لطلب غير مؤكد',
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
