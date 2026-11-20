<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Support\ApiException;

/**
 * BR-R04: the order was collected beyond its total after the return, so the difference goes back to the customer
 * in cash, and the merchant must confirm it (`refund: true`): `422 refund_required` with `details.refund_amount`.
 */
final class RefundRequired extends ApiException
{
    public static function amount(string $amount): self
    {
        return new self(
            422,
            'refund_required',
            "تم تحصيل أكثر من قيمة الطلب بعد المرتجع، يجب رد {$amount} للعميل",
            ['refund_amount' => $amount],
        );
    }
}
