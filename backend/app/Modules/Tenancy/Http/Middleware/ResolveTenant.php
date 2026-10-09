<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the request Host only (Tech §2, §5).
 * `{subdomain}.{base_domain}` -> Tenant. Anything else is a 404.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $subdomain = self::subdomainFromHost($request->getHost());

        if ($subdomain === null) {
            throw ApiException::notFound('المتجر غير موجود');
        }

        $tenant = Tenant::query()->where('subdomain', $subdomain)->first();

        if ($tenant === null) {
            throw ApiException::notFound('المتجر غير موجود');
        }

        $this->context->set($tenant);

        return $next($request);
    }

    public static function subdomainFromHost(string $host): ?string
    {
        $host = strtolower($host);
        $base = strtolower((string) config('tenancy.base_domain'));
        $suffix = '.'.$base;

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $subdomain = substr($host, 0, -strlen($suffix));

        if ($subdomain === '' || str_contains($subdomain, '.')) {
            return null;
        }

        if (in_array($subdomain, config('tenancy.reserved_subdomains', []), true)) {
            return null;
        }

        return $subdomain;
    }
}
