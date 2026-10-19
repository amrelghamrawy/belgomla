<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models\Scopes;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * BR-C06: delivered orders past their due date with something still owed on the net total (BR-O08):
 * `Order::query()->tap(new Overdue)`. Orders delivered before credit terms existed have no `due_at` and fall due on
 * delivery.
 */
final readonly class Overdue
{
    public function __construct(private ?CarbonImmutable $at = null) {}

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function __invoke(Builder $query): Builder
    {
        return $query
            ->where('orders.status', OrderStatus::Delivered)
            ->whereRaw('coalesce(orders.due_at, orders.delivered_at) < ?', [$this->at ?? CarbonImmutable::now()])
            ->whereRaw('orders.order_total - orders.returned_total - orders.collected_amount > 0');
    }
}
