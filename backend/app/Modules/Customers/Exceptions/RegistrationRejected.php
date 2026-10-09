<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Support\ApiException;

/** BR-CU08: a phone the merchant already invited registers through the invitation link, not a duplicate account. */
final class RegistrationRejected extends ApiException
{
    public static function useInvitation(): self
    {
        return new self(409, 'use_invitation', 'هذا الرقم مسجل بدعوة من المتجر، استخدم رابط الدعوة المرسل إليك');
    }
}
