<?php

declare(strict_types=1);

namespace App\Modules\Collections\Queries;

use App\Modules\Collections\Data\CollectionFiltersData;
use App\Modules\Collections\Models\CashCollection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * GET /api/dashboard/collections (08-api-contract.md §10): every collection of the tenant, voided ones included and
 * marked, newest collected first, with its order and customer.
 */
final readonly class CollectionsQuery
{
    /**
     * @return LengthAwarePaginator<int, CashCollection>
     */
    public function paginate(CollectionFiltersData $filters, int $perPage): LengthAwarePaginator
    {
        return CashCollection::query()
            ->with('order.customer')
            ->when($filters->from, fn (Builder $query, CarbonImmutable $from): Builder => $query->where('collected_at', '>=', $from))
            ->when($filters->to, fn (Builder $query, CarbonImmutable $to): Builder => $query->where('collected_at', '<=', $to))
            ->when($filters->customerId, fn (Builder $query, int $id): Builder => $query->whereHas('order', fn (Builder $orders): Builder => $orders->where('customer_id', $id)))
            ->when($filters->orderNumber, fn (Builder $query, int $number): Builder => $query->whereHas('order', fn (Builder $orders): Builder => $orders->where('number', $number)))
            ->orderByDesc('collected_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
