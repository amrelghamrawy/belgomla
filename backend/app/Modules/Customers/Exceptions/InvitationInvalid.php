<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Support\ApiException;

/** BR-CU08: an unknown, used, expired or superseded invitation link looks the same: 404. */
final class InvitationInvalid extends ApiException
{
    public static function make(): self
    {
        return new self(404, 'not_found', 'رابط الدعوة غير صالح أو منتهي الصلاحية');
    }
}
