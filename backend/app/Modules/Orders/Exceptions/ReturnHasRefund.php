<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Support\ApiException;

/**
 * BR-R06: a return whose cash refund is still live is voided only after the refund is (the same order of operations
 * as cancelling an order with collections, D7): `409 return_has_refund` with `details.refund_amount`.
 */
final class ReturnHasRefund extends ApiException
{
    public static function amount(string $amount): self
    {
        return new self(
            409,
            'return_has_refund',
            "تم رد {$amount} للعميل عن هذا المرتجع، ألغِ الرد من التحصيلات أولًا",
            ['refund_amount' => $amount],
        );
    }
}
