<?php

declare(strict_types=1);

namespace App\Modules\Collections\Queries;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * The summary of a customer statement (08-api-contract.md §10, BR-C08): the customer's non-cancelled orders with their
 * net totals, what was collected and what is owed on delivered ones (D59), plus the credit picture — limit, exposure
 * left under it (D77), the customer credit still unused (BR-C07) and what is overdue (BR-C06). The ledger rows come
 * from CustomerLedgerQuery.
 * Totals are net of returns (BR-R05); the summary's `outstanding` counts delivered orders only (D59), like the
 * customer list.
 */
final readonly class CustomerStatementQuery
{
    public function __construct(
        private CustomerExposureQuery $exposure,
        private OverdueQuery $overdue,
    ) {}

    /**
     * @return array{orders_count: int, total: string, collected: string, outstanding: string, credit_limit: string|null, payment_terms_days: int|null, available_credit: string|null, credit_balance: string, overdue: string}
     */
    public function summary(Customer $customer): array
    {
        /** @var object{orders_count: int|string, total: string, collected: string, outstanding: string} $row */
        $row = $this->of($customer)->toBase()
            ->selectRaw('count(*) as orders_count')
            ->selectRaw('coalesce(sum(order_total - returned_total), 0) as total')
            ->selectRaw('coalesce(sum(collected_amount), 0) as collected')
            ->selectRaw('coalesce(sum(order_total - returned_total - collected_amount) filter (where status = ?), 0) as outstanding', [OrderStatus::Delivered->value])
            ->first();

        return [
            'orders_count' => (int) $row->orders_count,
            'total' => Money::add('0.00', $row->total),
            'collected' => Money::add('0.00', $row->collected),
            'outstanding' => Money::add('0.00', $row->outstanding),
            'credit_limit' => $customer->credit_limit,
            'payment_terms_days' => $customer->payment_terms_days,
            'available_credit' => $customer->credit_limit === null ? null : Money::subtract($customer->credit_limit, $this->exposure->of($customer)),
            'credit_balance' => $this->exposure->credit($customer),
            'overdue' => $this->overdue->total($customer),
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
