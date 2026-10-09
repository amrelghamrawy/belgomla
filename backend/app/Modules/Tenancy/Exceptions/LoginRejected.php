<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Support\ApiException;

/** Every way a dashboard login can be refused. */
final class LoginRejected extends ApiException
{
    public static function invalidCredentials(): self
    {
        return new self(422, 'invalid_credentials', 'البريد الإلكتروني أو كلمة المرور غير صحيحة');
    }

    public static function userDisabled(): self
    {
        return new self(403, 'user_disabled', 'هذا الحساب معطّل');
    }

    public static function tooManyAttempts(): self
    {
        return new self(429, 'rate_limited', 'محاولات كثيرة، حاول بعد دقيقة');
    }

    public static function statefulRequestRequired(): self
    {
        return new self(400, 'stateful_request_required', 'يجب إرسال الطلب من واجهة المتجر');
    }
}
