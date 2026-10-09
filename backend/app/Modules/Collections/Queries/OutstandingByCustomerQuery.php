<?php

declare(strict_types=1);

namespace App\Modules\Collections\Queries;

use App\Modules\Customers\Contracts\OutstandingBalances;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * What customers still owe (D59): Σ (order_total − collected_amount) over their **delivered** orders that are not
 * fully collected. A deposit on an order that is not delivered yet lowers nothing here (it shows on the statement).
 * Fills Customers' OutstandingBalances (customer list and page) and feeds the outstanding report and the overview.
 */
final readonly class OutstandingByCustomerQuery implements OutstandingBalances
{
    /**
     * One row per customer that owes something: `customer_id, orders_count, total, collected, outstanding`.
     */
    public function builder(): QueryBuilder
    {
        return $this->owing()
            ->toBase()
            ->select('orders.customer_id')
            ->selectRaw('count(*) as orders_count')
            ->selectRaw('sum(orders.order_total) as total')
            ->selectRaw('sum(orders.collected_amount) as collected')
            ->selectRaw('sum(orders.order_total - orders.collected_amount) as outstanding')
            ->groupBy('orders.customer_id');
    }

    /**
     * @param  list<int>  $customerIds
     * @return array<int, string>
     */
    public function forCustomers(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $balances = [];

        foreach ($this->builder()->whereIn('orders.customer_id', $customerIds)->get() as $row) {
            /** @var object{customer_id: int, outstanding: string} $row */
            $balances[(int) $row->customer_id] = Money::add('0.00', $row->outstanding);
        }

        return $balances;
    }

    /** Everything owed to the tenant, for the dashboard overview (D5). */
    public function total(): string
    {
        $sum = $this->owing()->toBase()->selectRaw('coalesce(sum(orders.order_total - orders.collected_amount), 0) as outstanding')->value('outstanding');

        return Money::add('0.00', is_numeric($sum) ? (string) $sum : '0');
    }

    /**
     * @return Builder<Order>
     */
    private function owing(): Builder
    {
        return Order::query()
            ->where('orders.status', OrderStatus::Delivered)
            ->where('orders.collection_status', '!=', CollectionStatus::Collected);
    }
}
