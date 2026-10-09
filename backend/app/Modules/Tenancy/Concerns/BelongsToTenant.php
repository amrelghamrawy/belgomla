<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Apply to every tenant-owned model.
 * - Adds the TenantScope global scope.
 * - Fills tenant_id from the context on create.
 * - Refuses to create a row with a tenant_id different from the context.
 *
 * @property int $tenant_id
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if (! $context->has()) {
                throw new RuntimeException(sprintf(
                    'Cannot create %s without a tenant in context.', static::class
                ));
            }

            $current = $model->getAttribute('tenant_id');

            if ($current === null) {
                $model->setAttribute('tenant_id', $context->id());

                return;
            }

            $currentId = filter_var($current, FILTER_VALIDATE_INT);

            if ($currentId !== $context->id()) {
                throw new RuntimeException(sprintf(
                    'Refusing to create %s for tenant %s while tenant %d is in context.',
                    static::class, is_scalar($current) ? (string) $current : get_debug_type($current), $context->id()
                ));
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Escape hatch for platform-level code (CLI, reports). Use sparingly and consciously.
     *
     * @return Builder<self>
     */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }
}
