<?php

declare(strict_types=1);

namespace App\Modules\Collections\Exceptions;

use App\Support\ApiException;

/**
 * BR-C04: collections are recorded at any order status except cancelled: `409 order_cancelled`. A pre-order takes no
 * money (no deposits, D88): `409 is_preorder`; its converted orders are collected as usual.
 */
final class CannotCollect extends ApiException
{
    public static function cancelled(): self
    {
        return new self(409, 'order_cancelled', 'لا يمكن تسجيل تحصيل على طلب ملغي');
    }

    public static function preorder(): self
    {
        return new self(409, 'is_preorder', 'لا يُحصَّل على الحجز المسبق، يُحصَّل على الطلب بعد تحويله');
    }
}
