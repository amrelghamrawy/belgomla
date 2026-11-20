<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Queries;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * BR-INV07, D81 (CLI, `orders:expire-pending`): the active stores that let their unconfirmed orders cancel themselves.
 * A suspended store is frozen, so its orders wait for it to be activated again.
 */
final readonly class PendingExpiryTenantsQuery
{
    /**
     * @return Collection<int, Tenant>
     */
    public function get(): Collection
    {
        return Tenant::query()
            ->where('status', TenantStatus::Active)
            ->whereHas('settings', fn (Builder $settings): Builder => $settings->whereNotNull('pending_expiry_hours'))
            ->orderBy('id')
            ->get();
    }
}
