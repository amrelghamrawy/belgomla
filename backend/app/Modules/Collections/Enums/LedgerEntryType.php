<?php

declare(strict_types=1);

namespace App\Modules\Collections\Enums;

/** BR-C08: the kinds of rows on a customer statement (CustomerLedgerQuery). */
enum LedgerEntryType: string
{
    case Order = 'order';
    case Return = 'return';
    case Payment = 'payment';
    case CreditApplied = 'credit_applied';
    case Refund = 'refund';
    case RefundToCredit = 'refund_to_credit';
    case CreditCreated = 'credit_created';

    public function label(): string
    {
        return match ($this) {
            self::Order => 'طلب مسلَّم',
            self::Return => 'مرتجع',
            self::Payment => 'تحصيل',
            self::CreditApplied => 'خصم من الرصيد',
            self::Refund => 'رد نقدي',
            self::RefundToCredit => 'مرتجع إلى الرصيد',
            self::CreditCreated => 'رصيد للعميل',
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
