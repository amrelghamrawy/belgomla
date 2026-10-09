<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Support\ApiException;

final class TenantNotFound extends ApiException
{
    public static function forHost(): self
    {
        return new self(404, 'not_found', 'المتجر غير موجود');
    }
}
