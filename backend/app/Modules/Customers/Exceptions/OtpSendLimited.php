<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Support\ApiException;

/**
 * BR-CU07: at most 5 codes per phone per hour, and "send by SMS instead" only 60 seconds after the last code.
 * `429 rate_limited` with the seconds to wait, like Messaging's own backstop.
 */
final class OtpSendLimited extends ApiException
{
    public static function hourlyLimit(int $retryAfter): self
    {
        return new self(429, 'rate_limited', 'طلبت رموزًا كثيرة لهذا الرقم، حاول بعد قليل', ['retry_after' => $retryAfter]);
    }

    public static function smsTooSoon(int $retryAfter): self
    {
        return new self(429, 'rate_limited', 'يمكنك طلب الرمز برسالة نصية بعد دقيقة من آخر رمز', ['retry_after' => $retryAfter]);
    }
}
