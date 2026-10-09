<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Support\ApiException;

/** BR-CU07: at most 5 verification attempts per code; after that the code is dead and a new one is needed. */
final class OtpAttemptsExceeded extends ApiException
{
    public static function make(): self
    {
        return new self(422, 'otp_attempts_exceeded', 'تجاوزت عدد المحاولات المسموح، اطلب رمزًا جديدًا');
    }
}
