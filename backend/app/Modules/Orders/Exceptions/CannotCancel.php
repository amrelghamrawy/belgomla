<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Orders\Enums\OrderStatus;
use App\Support\ApiException;

/**
 * BR-O02: a customer cancels only a pending order; nobody cancels a delivered or cancelled one. Same code as
 * any refused transition, `409 invalid_transition` (08-api-contract.md §3).
 */
final class CannotCancel extends ApiException
{
    public static function byCustomer(OrderStatus $status): self
    {
        return new self(
            409,
            'invalid_transition',
            'لا يمكن إلغاء الطلب بعد تأكيده، تواصل مع المتجر',
            ['from' => $status->value, 'to' => OrderStatus::Cancelled->value],
        );
    }

    public static function terminal(OrderStatus $status): self
    {
        return new self(
            409,
            'invalid_transition',
            "لا يمكن إلغاء طلب حالته «{$status->label()}»",
            ['from' => $status->value, 'to' => OrderStatus::Cancelled->value],
        );
    }
}
