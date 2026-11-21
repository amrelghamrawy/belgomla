<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Support\ApiException;

/**
 * D112, BR-T10: every way a dashboard password reset can be refused. The codes match the customer ones (BR-CU07), so
 * the frontend reads both the same way.
 */
final class ResetCodeRejected extends ApiException
{
    public static function wrongCode(int $attemptsLeft): self
    {
        return new self(422, 'otp_invalid', 'رمز التحقق غير صحيح', ['attempts_left' => $attemptsLeft]);
    }

    public static function noLiveCode(): self
    {
        return new self(422, 'otp_invalid', 'رمز التحقق غير صحيح');
    }

    public static function expired(): self
    {
        return new self(422, 'otp_expired', 'انتهت صلاحية رمز التحقق، اطلب رمزًا جديدًا');
    }

    public static function attemptsExceeded(): self
    {
        return new self(422, 'otp_attempts_exceeded', 'تجاوزت عدد المحاولات المسموح، اطلب رمزًا جديدًا');
    }

    public static function hourlyLimit(int $retryAfter): self
    {
        return new self(429, 'rate_limited', 'طلبت رموزًا كثيرة لهذا الحساب، حاول بعد قليل', ['retry_after' => $retryAfter]);
    }
}
