<?php

declare(strict_types=1);

namespace App\Modules\Collections\Queries;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * GET /api/dashboard/customers/{id}/statement (08-api-contract.md §10, D24): the customer's orders with their totals,
 * what was collected and the balance left, newest first; cancelled orders carry no money (BR-O03) and are left out.
 * The summary's `outstanding` counts delivered orders only (D59), like the customer list.
 */
final readonly class CustomerStatementQuery
{
    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function orders(Customer $customer, int $perPage): LengthAwarePaginator
    {
        return $this->of($customer)->orderByDesc('placed_at')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * @return array{orders_count: int, total: string, collected: string, outstanding: string}
     */
    public function summary(Customer $customer): array
    {
        /** @var object{orders_count: int|string, total: string, collected: string, outstanding: string} $row */
        $row = $this->of($customer)->toBase()
            ->selectRaw('count(*) as orders_count')
            ->selectRaw('coalesce(sum(order_total), 0) as total')
            ->selectRaw('coalesce(sum(collected_amount), 0) as collected')
            ->selectRaw('coalesce(sum(order_total - collected_amount) filter (where status = ?), 0) as outstanding', [OrderStatus::Delivered->value])
            ->first();

        return [
            'orders_count' => (int) $row->orders_count,
            'total' => Money::add('0.00', $row->total),
            'collected' => Money::add('0.00', $row->collected),
            'outstanding' => Money::add('0.00', $row->outstanding),
        ];
    }

    /**
     * @return Builder<Order>
     */
    private function of(Customer $customer): Builder
    {
        return Order::query()->where('customer_id', $customer->id)->where('status', '!=', OrderStatus::Cancelled);
    }
}
