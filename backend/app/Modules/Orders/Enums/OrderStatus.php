<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * BR-O01 (07-domain-model.md §11): `pending → confirmed → preparing → out_for_delivery → delivered`, forward only,
 * steps may be skipped; `cancelled` from any status before `delivered`. `delivered` and `cancelled` are terminal.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'قيد الانتظار',
            self::Confirmed => 'مؤكد',
            self::Preparing => 'قيد التجهيز',
            self::OutForDelivery => 'خرج للتوصيل',
            self::Delivered => 'تم التوصيل',
            self::Cancelled => 'ملغي',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::Cancelled;
    }

    public function canTransitionTo(self $target): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        if ($target === self::Cancelled) {
            return true;
        }

        return $this->step() < $target->step();
    }

    /** BR-O10, D9: the merchant may still edit the delivery address. */
    public function allowsAddressEdit(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::Preparing], true);
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

    /** Position on the forward path; `cancelled` is off the path. */
    private function step(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Confirmed => 1,
            self::Preparing => 2,
            self::OutForDelivery => 3,
            self::Delivered => 4,
            self::Cancelled => -1,
        };
    }
}
