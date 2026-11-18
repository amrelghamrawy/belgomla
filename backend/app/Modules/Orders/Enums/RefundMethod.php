<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * BR-R04, BR-C07, BR-C10: how a return's over-collection goes back to the customer: paid back in cash, by InstaPay,
 * to a wallet or by bank transfer (D103, the codes of Collections' recordable methods), or kept as credit for their
 * next orders (V2 Task 02).
 */
enum RefundMethod: string
{
    case Cash = 'cash';
    case Instapay = 'instapay';
    case Wallet = 'wallet';
    case BankTransfer = 'bank_transfer';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'رد نقدي',
            self::Instapay => 'رد بإنستاباي',
            self::Wallet => 'رد على محفظة إلكترونية',
            self::BankTransfer => 'رد بتحويل بنكي',
            self::Credit => 'رصيد للعميل',
        };
    }

    /** Paid back to the customer (any method but credit). */
    public function paysBack(): bool
    {
        return $this !== self::Credit;
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
