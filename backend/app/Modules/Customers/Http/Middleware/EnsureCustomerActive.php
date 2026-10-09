<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Middleware;

use App\Modules\Customers\Exceptions\CustomerNotActive;
use App\Modules\Customers\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-CU05: only `active` customers use the cart and addresses. A session can outlive the status it was
 * started with (blocked by the merchant, or verified while approval is required), so the status is checked
 * on every request (`403` with the status as the code). Runs after `auth:customer`.
 */
final readonly class EnsureCustomerActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user('customer');

        if ($customer instanceof Customer && ! $customer->status->canLogin()) {
            throw CustomerNotActive::for($customer->status);
        }

        return $next($request);
    }
}
