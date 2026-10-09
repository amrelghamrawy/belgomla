<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Middleware;

use App\Modules\Customers\Exceptions\PricesHidden;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D1, D53: when the tenant hides prices from guests, a guest adding to the cart is told why (`403
 * prices_hidden`) instead of a bare `401`, so the storefront can show "log in to see prices". Otherwise it
 * passes and `auth:customer` answers. Placed before AuthenticatesRequests in the priority list.
 */
final readonly class RejectGuestsWhenPricesHidden
{
    public function __construct(private CurrentTenantSettingsQuery $settings) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user('customer') === null && $this->settings->requiresLoginForPrices()) {
            throw PricesHidden::make();
        }

        return $next($request);
    }
}
