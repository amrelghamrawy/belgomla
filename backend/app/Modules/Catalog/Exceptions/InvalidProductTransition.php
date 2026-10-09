<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Support\ApiException;

/** BR-P04 / 07-domain-model.md §11: the requested status change is not an allowed transition. */
final class InvalidProductTransition extends ApiException
{
    public static function from(ProductStatus $from, ProductStatus $to): self
    {
        return new self(
            409,
            'invalid_transition',
            "لا يمكن تغيير حالة المنتج من «{$from->label()}» إلى «{$to->label()}»",
            ['from' => $from->value, 'to' => $to->value],
        );
    }
}
