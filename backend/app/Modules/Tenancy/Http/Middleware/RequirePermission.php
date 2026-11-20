<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Enums\Permission;
use App\Modules\Tenancy\Exceptions\PermissionDenied;
use App\Modules\Tenancy\Models\TenantUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-T06, D82: `->middleware('permission:orders.manage')` lets the request through when the dashboard user's role has
 * the permission; several (`permission:customers.manage,customers.credit`) mean any one of them. Otherwise
 * `403 forbidden`. Runs after `auth:tenant_user`, so a guest has already been refused with 401.
 */
final readonly class RequirePermission
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user('tenant_user');

        foreach ($permissions as $permission) {
            if ($user instanceof TenantUser && $user->role->can(Permission::from($permission))) {
                return $next($request);
            }
        }

        throw PermissionDenied::make();
    }
}
