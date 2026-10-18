<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Orders\Enums\OrderStatus;
use App\Support\ApiException;

/**
 * BR-R01: only a delivered order takes returns; before delivery the merchant cancels instead:
 * `409 return_not_allowed` with `details.status`.
 */
final class ReturnNotAllowed extends ApiException
{
    public static function status(OrderStatus $status): self
    {
        return new self(
            409,
            'return_not_allowed',
            "لا يمكن تسجيل مرتجع على طلب حالته «{$status->label()}»، المرتجع للطلبات المسلّمة فقط",
            ['status' => $status->value],
        );
    }
}
