<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Data\OrderFiltersData;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Scopes\Stale;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * GET /api/dashboard/orders (08-api-contract.md §9): every order of the tenant with its customer, filtered by
 * status, collection status, customer, placing day range, a search (order number, customer name or phone,
 * recipient name) and `stale` — pending for longer than the tenant's `stale_pending_hours` (BR-INV07, D10).
 */
final readonly class DashboardOrdersQuery
{
    public function __construct(private CurrentTenantSettingsQuery $settings) {}

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(OrderFiltersData $filters, int $perPage): LengthAwarePaginator
    {
        $query = Order::query()->with('customer');

        $this->filter($query, $filters);

        [$column, $direction] = $filters->sort;

        return $query->orderBy($column, $direction)->orderBy('id', $direction)->paginate($perPage);
    }

    /** Pending orders placed before this moment are stale (BR-INV07). */
    public function staleBefore(): CarbonImmutable
    {
        return Stale::threshold($this->settings->get()->stale_pending_hours);
    }

    /**
     * @param  Builder<Order>  $query
     */
    private function filter(Builder $query, OrderFiltersData $filters): void
    {
        $query
            ->when($filters->status, fn (Builder $query, OrderStatus $status): Builder => $query->where('status', $status))
            ->when($filters->collectionStatus, fn (Builder $query, CollectionStatus $status): Builder => $query->where('collection_status', $status))
            ->when($filters->customerId, fn (Builder $query, int $id): Builder => $query->where('customer_id', $id))
            ->when($filters->from, fn (Builder $query, CarbonImmutable $from): Builder => $query->where('placed_at', '>=', $from))
            ->when($filters->to, fn (Builder $query, CarbonImmutable $to): Builder => $query->where('placed_at', '<=', $to))
            ->when($filters->search, fn (Builder $query, string $search): Builder => $this->search($query, $search))
            ->when($filters->stale, fn (Builder $query): Builder => $query->tap(new Stale($this->settings->get()->stale_pending_hours)));
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function search(Builder $query, string $search): Builder
    {
        $number = mb_ltrim($search, '#');
        $digits = mb_ltrim((string) preg_replace('/\D/', '', $search), '0');
        $like = '%'.addcslashes($search, '%_\\').'%';

        return $query->where(function (Builder $query) use ($number, $digits, $like): void {
            if (ctype_digit($number) && mb_strlen($number) <= 9) {
                $query->orWhere('number', (int) $number);
            }

            $query->orWhere('delivery_name', 'ilike', $like)
                ->orWhereHas('customer', function (Builder $customer) use ($like, $digits): void {
                    $customer->where('name', 'ilike', $like);

                    if ($digits !== '') {
                        $customer->orWhere('phone', 'like', '%'.$digits.'%');
                    }
                });
        });
    }
}
