<?php

declare(strict_types=1);

namespace App\Modules\Collections\Queries;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Scopes\Overdue;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * BR-C06: what is overdue — delivered orders past their due date with a net balance left — for a customer (statement
 * summary, customer page) and for the store (overview).
 */
final readonly class OverdueQuery
{
    /** Σ net balance of the overdue orders, optionally of one customer. */
    public function total(?Customer $customer = null): string
    {
        $sum = $this->overdue($customer)->toBase()->selectRaw('coalesce(sum(orders.order_total - orders.returned_total - orders.collected_amount), 0) as overdue')->value('overdue');

        return Money::add('0.00', is_numeric($sum) ? (string) $sum : '0');
    }

    /** How many customers have something overdue. */
    public function customersCount(): int
    {
        return $this->overdue()->distinct()->count('orders.customer_id');
    }

    /**
     * @return Builder<Order>
     */
    private function overdue(?Customer $customer = null): Builder
    {
        return Order::query()->tap(new Overdue)->when($customer, fn (Builder $query, Customer $customer): Builder => $query->where('orders.customer_id', $customer->id));
    }
}
