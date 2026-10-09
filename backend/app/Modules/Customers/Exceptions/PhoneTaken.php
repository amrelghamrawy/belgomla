<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Support\ApiException;

/** BR-CU04: the phone is unique per tenant. */
final class PhoneTaken extends ApiException
{
    public static function make(): self
    {
        return new self(409, 'phone_taken', 'رقم الهاتف مسجل بالفعل');
    }
}
