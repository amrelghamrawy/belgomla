<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use App\Support\ApiException;

/** BR-C04: collections are recorded at any order status except cancelled: `409 order_cancelled`. */
final class CannotCollect extends ApiException
{
    public static function cancelled(): self
    {
        return new self(409, 'order_cancelled', 'لا يمكن تسجيل تحصيل على طلب ملغي');
    }
}
