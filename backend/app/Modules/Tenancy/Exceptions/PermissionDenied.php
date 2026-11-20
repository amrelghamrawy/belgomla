<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Support\ApiException;

/** BR-T06, D82: the dashboard user's role does not include what the endpoint needs (`403 forbidden`). */
final class PermissionDenied extends ApiException
{
    public static function make(): self
    {
        return new self(403, 'forbidden', 'ليس لديك صلاحية لهذا الإجراء');
    }
}
