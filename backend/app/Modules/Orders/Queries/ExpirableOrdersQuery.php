<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Scopes\Stale;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use Illuminate\Database\Eloquent\Collection;

/**
 * BR-INV07, D81: the current tenant's pending orders placed more than `pending_expiry_hours` ago, oldest first;
 * none while the setting is off. ExpirePendingOrder re-checks each one under a lock.
 */
final readonly class ExpirableOrdersQuery
{
    public function __construct(private CurrentTenantSettingsQuery $settings) {}

    /**
     * @return Collection<int, Order>
     */
    public function get(): Collection
    {
        $hours = $this->settings->get()->pending_expiry_hours;

        if ($hours === null) {
            return new Collection;
        }

        return Order::query()->tap(new Stale($hours))->orderBy('placed_at')->orderBy('id')->get();
    }
}
