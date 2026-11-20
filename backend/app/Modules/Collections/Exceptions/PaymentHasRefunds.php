<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use App\Support\ApiException;

/**
 * BR-R04: refunds pay back part of what was collected, so voiding a payment may not leave more refunded than
 * collected: `409 void_refund_first`; the refund is voided first.
 */
final class PaymentHasRefunds extends ApiException
{
    public static function make(): self
    {
        return new self(409, 'void_refund_first', 'تم رد مبالغ للعميل من هذا الطلب أكبر من الباقي بعد الإلغاء، ألغِ الرد أولًا');
    }
}
