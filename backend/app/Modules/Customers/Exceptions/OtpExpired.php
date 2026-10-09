<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Support\ApiException;

/** BR-CU07: a code is valid 10 minutes. */
final class OtpExpired extends ApiException
{
    public static function make(): self
    {
        return new self(422, 'otp_expired', 'انتهت صلاحية رمز التحقق، اطلب رمزًا جديدًا');
    }
}
