<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Support\ApiException;

/** BR-CU07: a wrong code, or no live code for this phone and purpose. `details.attempts_left` when one exists. */
final class OtpInvalid extends ApiException
{
    public static function wrongCode(int $attemptsLeft): self
    {
        return new self(422, 'otp_invalid', 'رمز التحقق غير صحيح', ['attempts_left' => $attemptsLeft]);
    }

    public static function noLiveCode(): self
    {
        return new self(422, 'otp_invalid', 'رمز التحقق غير صحيح');
    }
}
