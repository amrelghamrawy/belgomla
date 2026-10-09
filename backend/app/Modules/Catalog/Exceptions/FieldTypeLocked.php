<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Support\ApiException;

/** BR-P08: a field's type cannot change once products hold values for it. */
final class FieldTypeLocked extends ApiException
{
    public static function make(): self
    {
        return new self(409, 'field_type_locked', 'لا يمكن تغيير نوع حقل له قيم في المنتجات');
    }
}
