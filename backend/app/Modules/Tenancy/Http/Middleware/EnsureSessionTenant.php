<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tech §6: a session created on one tenant host must never authenticate on another.
 * Runs after auth:* middleware. Also verifies the authenticated model's own tenant_id.
 */
class EnsureSessionTenant
{
    public const SESSION_KEY = 'tenant_id';

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $guard): Response
    {
        $tenantId = $this->context->id();
        $user = $request->user($guard);

        $sessionTenant = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (($sessionTenant !== null && (int) $sessionTenant !== $tenantId)
            || ($user !== null && (int) $user->tenant_id !== $tenantId)) {
            auth($guard)->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            throw new AuthenticationException('Unauthenticated.', [$guard]);
        }

        return $next($request);
    }
}
