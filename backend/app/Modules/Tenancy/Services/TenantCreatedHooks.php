<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Tenant;

/**
 * Other modules register work to run inside the CreateTenant transaction, in tenant context
 * (e.g. Catalog applies the vertical template). Registered from service providers.
 */
final class TenantCreatedHooks
{
    /** @var list<callable(Tenant): void> */
    private array $hooks = [];

    /**
     * @param  callable(Tenant): void  $hook
     */
    public function register(callable $hook): void
    {
        $this->hooks[] = $hook;
    }

    public function run(Tenant $tenant): void
    {
        foreach ($this->hooks as $hook) {
            $hook($tenant);
        }
    }
}
