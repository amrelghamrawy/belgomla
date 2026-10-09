<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Support\ApiException;

/** BR-T07: suspended tenant, storefront surface. */
final class StoreUnavailable extends ApiException
{
    public static function suspended(): self
    {
        return new self(503, 'store_unavailable', 'المتجر غير متاح حاليًا');
    }
}
