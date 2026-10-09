<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Support\ApiException;

final class TenantUserConflict extends ApiException
{
    public static function emailTaken(): self
    {
        return new self(409, 'email_taken', 'هذا البريد الإلكتروني مستخدم بالفعل');
    }

    public static function cannotDisableSelf(): self
    {
        return new self(409, 'cannot_disable_self', 'لا يمكنك تعطيل حسابك الحالي');
    }

    public static function lastActiveUser(): self
    {
        return new self(409, 'last_active_user', 'لا يمكن تعطيل آخر مستخدم نشط في المتجر');
    }
}
