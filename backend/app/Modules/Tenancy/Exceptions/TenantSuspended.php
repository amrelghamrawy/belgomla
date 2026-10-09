<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Support\ApiException;

/** BR-T07: suspended tenant, dashboard surface. */
final class TenantSuspended extends ApiException
{
    public static function forDashboard(): self
    {
        return new self(403, 'tenant_suspended', 'هذا المتجر موقوف حاليًا');
    }
}
