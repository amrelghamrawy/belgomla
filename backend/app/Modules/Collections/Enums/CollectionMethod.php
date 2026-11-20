<?php

declare(strict_types=1);

namespace App\Modules\Collections\Enums;

/**
 * BR-C02, BR-C10 (D103): how a payment or a refund moved. The merchant records cash, InstaPay, a wallet or a bank
 * transfer (`recordable()`, no gateway, no reconciliation); `credit` is the system's method for drawing on or
 * creating customer credit (BR-C07) and is never chosen.
 */
enum CollectionMethod: string
{
    case Cash = 'cash';
    case Instapay = 'instapay';
    case Wallet = 'wallet';
    case BankTransfer = 'bank_transfer';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'نقدي',
            self::Instapay => 'إنستاباي',
            self::Wallet => 'محفظة إلكترونية',
            self::BankTransfer => 'تحويل بنكي',
            self::Credit => 'رصيد العميل',
        };
    }

    /**
     * D103 a: the methods the merchant may choose.
     *
     * @return list<self>
     */
    public static function recordable(): array
    {
        return [self::Cash, self::Instapay, self::Wallet, self::BankTransfer];
    }

    /**
     * @return list<string>
     */
    public static function recordableValues(): array
    {
        return array_map(static fn (self $method): string => $method->value, self::recordable());
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
