<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Exceptions;

use App\Support\ApiException;

/**
 * BR-CU07: more than 5 messages to one phone within an hour. `429 rate_limited` with the seconds to wait.
 */
final class MessagingRateLimited extends ApiException
{
    public static function forPhone(int $retryAfter): self
    {
        return new self(429, 'rate_limited', 'تم إرسال رسائل كثيرة إلى هذا الرقم، حاول بعد قليل', ['retry_after' => $retryAfter]);
    }
}
