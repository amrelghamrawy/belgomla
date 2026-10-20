<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Data\ReturnFiltersData;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\Scopes\Live;
use App\Modules\Orders\Services\ReturnableQuantities;
use App\Modules\Orders\Services\ReturnPricing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Returns for the dashboard (08-api-contract.md §9, BR-R01..R06): every return of the tenant, voided ones included,
 * newest `returned_at` first, with its order, customer and lines; one order's returns oldest first; and what each
 * line of an order can still take back (BR-R02).
 */
final readonly class ReturnsQuery
{
    public function __construct(private ReturnableQuantities $returnable, private ReturnPricing $pricing) {}

    /**
     * @return LengthAwarePaginator<int, OrderReturn>
     */
    public function paginate(ReturnFiltersData $filters, int $perPage): LengthAwarePaginator
    {
        return OrderReturn::query()
            ->with(['order.customer', 'items.orderItem'])
            ->when($filters->from, fn (Builder $query, CarbonImmutable $from): Builder => $query->where('returned_at', '>=', $from))
            ->when($filters->to, fn (Builder $query, CarbonImmutable $to): Builder => $query->where('returned_at', '<=', $to))
            ->when($filters->customerId, fn (Builder $query, int $id): Builder => $query->whereHas('order', fn (Builder $orders): Builder => $orders->where('customer_id', $id)))
            ->when($filters->search, fn (Builder $query, string $search): Builder => $this->search($query, $search))
            ->orderByDesc('returned_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, OrderReturn>
     */
    public function forOrder(Order $order): Collection
    {
        return OrderReturn::query()->where('order_id', $order->id)->with('items.orderItem')->orderBy('number')->get();
    }

    /**
     * Every live line of the order with what was ordered, delivered (BR-O13), brought back by live returns and can still
     * come back.
     *
     * @return list<array{order_item_id: int, product_name: string, sku_code: string, unit: string, unit_price: string, ordered: int, delivered: int, returned: int, returnable: int}>
     */
    public function returnable(Order $order): array
    {
        $returned = $this->returnable->returned($order);

        return array_values(OrderItem::query()->where('order_id', $order->id)->tap(new Live)->orderBy('id')->get()->map(fn (OrderItem $item): array => [
            'order_item_id' => $item->id,
            'product_name' => $item->product_name,
            'sku_code' => $item->sku_code,
            'unit' => $item->unit_name,
            'unit_price' => $this->pricing->prorated($order, $item->unit_price), // what a unit returns at (D85)
            'ordered' => $item->quantity,
            'delivered' => $item->delivered_quantity ?? $item->quantity,
            'returned' => $returned[$item->id] ?? 0,
            'returnable' => ($item->delivered_quantity ?? $item->quantity) - ($returned[$item->id] ?? 0),
        ])->all());
    }

    /**
     * @param  Builder<OrderReturn>  $query
     * @return Builder<OrderReturn>
     */
    private function search(Builder $query, string $search): Builder
    {
        $number = mb_ltrim($search, '#');
        $like = '%'.addcslashes($search, '%_\\').'%';

        return $query->whereHas('order', function (Builder $orders) use ($number, $like): void {
            $orders->where(function (Builder $orders) use ($number, $like): void {
                if (ctype_digit($number) && mb_strlen($number) <= 9) {
                    $orders->orWhere('number', (int) $number);
                }

                $orders->orWhereHas('customer', fn (Builder $customer): Builder => $customer->where('name', 'ilike', $like));
            });
        });
    }
}
