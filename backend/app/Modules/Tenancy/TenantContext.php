<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Models\Tenant;
use RuntimeException;

/**
 * Holds the tenant resolved for the current request (or CLI run).
 * Registered as a singleton; every tenant-owned query reads from it.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function has(): bool
    {
        return $this->tenant instanceof Tenant;
    }

    public function get(): Tenant
    {
        if (! $this->tenant instanceof Tenant) {
            throw new RuntimeException('No tenant is set in the current context.');
        }

        return $this->tenant;
    }

    public function id(): int
    {
        return $this->get()->id;
    }

    public function idOrNull(): ?int
    {
        return $this->tenant?->id;
    }

    /**
     * Run a callback with a specific tenant set, restoring the previous one afterwards.
     *
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $callback
     * @return TReturn
     */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback($tenant);
        } finally {
            $this->tenant = $previous;
        }
    }
}
