<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $guard): Response
    {
        $tenantId = $this->context->id();

        if ($this->sessionBelongsToAnotherTenant($request, $tenantId) || $this->userBelongsToAnotherTenant($request, $guard, $tenantId)) {
            auth($guard)->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            throw new AuthenticationException('Unauthenticated.', [$guard]);
        }

        return $next($request);
    }

    private function sessionBelongsToAnotherTenant(Request $request, int $tenantId): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $sessionTenant = $request->session()->get(self::SESSION_KEY);

        return $sessionTenant !== null && filter_var($sessionTenant, FILTER_VALIDATE_INT) !== $tenantId;
    }

    private function userBelongsToAnotherTenant(Request $request, string $guard, int $tenantId): bool
    {
        $user = $request->user($guard);

        if (! $user instanceof Model) {
            return false;
        }

        return filter_var($user->getAttribute('tenant_id'), FILTER_VALIDATE_INT) !== $tenantId;
    }
}
