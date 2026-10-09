<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Http\Middleware\EnsureSessionTenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * Starts and ends a storefront session on the `customer` guard, bound to the host's tenant (Tech §6): the
 * session id is regenerated against fixation and the tenant id stored for EnsureSessionTenant.
 */
final readonly class CustomerSession
{
    public const string GUARD = 'customer';

    public function __construct(private TenantContext $context) {}

    public function start(Customer $customer, Session $session): void
    {
        Auth::guard(self::GUARD)->login($customer);
        $session->regenerate();
        $session->put(EnsureSessionTenant::SESSION_KEY, $this->context->id());
    }

    public function end(Session $session): void
    {
        Auth::guard(self::GUARD)->logout();
        $session->invalidate();
        $session->regenerateToken();
    }
}
