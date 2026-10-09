<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Support\ApiException;

/** Every way a storefront login can be refused (BR-CU04, BR-CU05). The status is only revealed after the password matched. */
final class LoginRejected extends ApiException
{
    public static function invalidCredentials(): self
    {
        return new self(422, 'invalid_credentials', 'رقم الهاتف أو كلمة المرور غير صحيحة');
    }

    public static function notActive(CustomerStatus $status): self
    {
        return match ($status) {
            CustomerStatus::PendingApproval => new self(403, 'pending_approval', 'حسابك في انتظار موافقة المتجر'),
            CustomerStatus::Blocked => new self(403, 'blocked', 'تم إيقاف حسابك، تواصل مع المتجر'),
            default => new self(403, 'phone_not_verified', 'لم يتم تأكيد رقم الهاتف بعد'),
        };
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
