<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Exceptions\StoreUnavailable;
use App\Modules\Tenancy\Exceptions\TenantSuspended;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-T07: a suspended tenant's storefront answers 503, its dashboard answers 403.
 * Parameter: "store" (default) or "dashboard".
 */
final readonly class EnsureTenantActive
{
    public function __construct(private TenantContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $surface = 'store'): Response
    {
        if ($this->context->get()->isSuspended()) {
            throw $surface === 'dashboard' ? TenantSuspended::forDashboard() : StoreUnavailable::suspended();
        }

        return $next($request);
    }
}
