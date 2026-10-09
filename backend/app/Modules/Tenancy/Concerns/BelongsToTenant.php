<?php

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Apply to every tenant-owned model.
 * - Adds the TenantScope global scope.
 * - Fills tenant_id from the context on create.
 * - Refuses to create a row with a tenant_id different from the context.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if (! $context->has()) {
                throw new RuntimeException(sprintf(
                    'Cannot create %s without a tenant in context.', static::class
                ));
            }

            if ($model->tenant_id === null) {
                $model->tenant_id = $context->id();
            } elseif ((int) $model->tenant_id !== $context->id()) {
                throw new RuntimeException(sprintf(
                    'Refusing to create %s for tenant %d while tenant %d is in context.',
                    static::class, $model->tenant_id, $context->id()
                ));
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Escape hatch for platform-level code (CLI, reports). Use sparingly and consciously.
     */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }
}
