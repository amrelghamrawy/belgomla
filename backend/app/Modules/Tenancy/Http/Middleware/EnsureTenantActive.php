<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\TenantContext;
use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-T07: a suspended tenant's storefront answers 503, its dashboard answers 403.
 * Parameter: "store" (default) or "dashboard".
 */
class EnsureTenantActive
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $surface = 'store'): Response
    {
        $tenant = $this->context->get();

        if ($tenant->isSuspended()) {
            throw $surface === 'dashboard'
                ? ApiException::forbidden('tenant_suspended', 'هذا المتجر موقوف حاليًا')
                : ApiException::unavailable('store_unavailable', 'المتجر غير متاح حاليًا');
        }

        return $next($request);
    }
}
