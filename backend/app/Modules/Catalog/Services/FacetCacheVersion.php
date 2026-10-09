<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Per-tenant version number baked into every facet cache key (Tech §9b). Bumping it invalidates
 * all cached facet counts of the tenant at once without having to know which keys exist.
 */
final readonly class FacetCacheVersion
{
    public function current(int $tenantId): int
    {
        $version = Cache::get($this->key($tenantId), 0);

        return is_numeric($version) ? (int) $version : 0;
    }

    public function bump(int $tenantId): void
    {
        if (! Cache::has($this->key($tenantId))) {
            Cache::forever($this->key($tenantId), 0);
        }

        Cache::increment($this->key($tenantId));
    }

    private function key(int $tenantId): string
    {
        return "catalog:facets:{$tenantId}:version";
    }
}
