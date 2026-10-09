<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Observers;

use App\Modules\Catalog\Services\FacetCacheVersion;
use Illuminate\Database\Eloquent\Model;

/**
 * Cache busting only: categories, field definitions and options shape the storefront facets, so any
 * change to them invalidates the tenant's cached facet counts (Tech §9b). Products are handled by
 * ProductObserver.
 */
final readonly class FacetCacheObserver
{
    public function __construct(private FacetCacheVersion $facets) {}

    public function saved(Model $model): void
    {
        $this->bump($model);
    }

    public function deleted(Model $model): void
    {
        $this->bump($model);
    }

    private function bump(Model $model): void
    {
        $tenantId = $model->getAttribute('tenant_id');

        if (is_int($tenantId)) {
            $this->facets->bump($tenantId);
        }
    }
}
