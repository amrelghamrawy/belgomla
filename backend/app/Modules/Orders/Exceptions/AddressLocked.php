<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Orders\Enums\OrderStatus;
use App\Support\ApiException;

/** BR-O10, D9: the delivery address is edited only before the order goes out for delivery. */
final class AddressLocked extends ApiException
{
    public static function at(OrderStatus $status): self
    {
        return new self(
            409,
            'address_locked',
            "لا يمكن تعديل عنوان التوصيل لطلب حالته «{$status->label()}»",
            ['status' => $status->value],
        );
    }
}
