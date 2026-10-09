<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Orders\Enums\OrderStatus;
use App\Support\ApiException;

/** BR-O01: backwards, onto itself, or out of a terminal status: `409 invalid_transition` with `details.from/to`. */
final class InvalidOrderTransition extends ApiException
{
    public static function from(OrderStatus $from, OrderStatus $to): self
    {
        return new self(
            409,
            'invalid_transition',
            "لا يمكن نقل الطلب من «{$from->label()}» إلى «{$to->label()}»",
            ['from' => $from->value, 'to' => $to->value],
        );
    }
}
