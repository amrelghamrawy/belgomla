<?php

declare(strict_types=1);

namespace App\Modules\Collections\Queries;

use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BR-C08: a customer's account statement as a dated ledger of everything that changed what they owe — orders when
 * delivered (debit), returns (credit), payments (credit; a payment drawn from customer credit is `credit_applied`), cash
 * refunds (debit), returns kept as credit (`refund_to_credit`, debit) and the credit created by them (an info line
 * without amounts). A payment or refund row carries its method and reference (BR-C10). Voided rows are left out. One `UNION ALL` ordered by date, then by kind, then by id, with a running
 * balance (a window function) that starts from everything before `from` (the opening balance, D60 Cairo days are
 * turned into UTC bounds by the caller). Positive balance = the customer owes the store.
 */
final readonly class CustomerLedgerQuery
{
    /**
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(Customer $customer, ?CarbonImmutable $from, ?CarbonImmutable $to, int $perPage): LengthAwarePaginator
    {
        return $this->withBalance($customer)
            ->when($from, fn (Builder $query, CarbonImmutable $from): Builder => $query->where('occurred_at', '>=', $from))
            ->when($to, fn (Builder $query, CarbonImmutable $to): Builder => $query->where('occurred_at', '<=', $to))
            ->orderBy('occurred_at')
            ->orderBy('sort')
            ->orderBy('ref_id')
            ->paginate($perPage);
    }

    /** What the customer owed before the range starts (nothing before the first row). */
    public function openingBalance(Customer $customer, ?CarbonImmutable $from): string
    {
        if (! $from instanceof CarbonImmutable) {
            return '0.00';
        }

        return $this->sum(DB::query()->fromSub($this->rows($customer), 'ledger')->where('occurred_at', '<', $from));
    }

    /** What the customer owes at the end of the range (everything up to `to`, or every row). */
    public function closingBalance(Customer $customer, ?CarbonImmutable $to): string
    {
        return $this->sum(DB::query()->fromSub($this->rows($customer), 'ledger')
            ->when($to, fn (Builder $query, CarbonImmutable $to): Builder => $query->where('occurred_at', '<=', $to)));
    }

    private function sum(Builder $query): string
    {
        $sum = $query->selectRaw('coalesce(sum(debit - credit), 0) as balance')->value('balance');

        return Money::add('0.00', is_numeric($sum) ? (string) $sum : '0');
    }

    /** Every row with its running balance, before the range filter (so the balance counts earlier rows). */
    private function withBalance(Customer $customer): Builder
    {
        $running = DB::query()
            ->fromSub($this->rows($customer), 'ledger')
            ->select('ledger.*')
            ->selectRaw('sum(debit - credit) over (order by occurred_at, sort, ref_id rows between unbounded preceding and current row) as balance');

        return DB::query()->fromSub($running, 'ledger');
    }

    private function rows(Customer $customer): Builder
    {
        $delivered = Order::query()->toBase()
            ->where('orders.customer_id', $customer->id)
            ->where('orders.status', OrderStatus::Delivered->value)
            ->selectRaw("orders.delivered_at as occurred_at, 1 as sort, 'order'::text as type, orders.number as order_number, null::integer as return_number, orders.order_total as debit, 0::numeric(12,2) as credit, orders.id as ref_id, null::text as method, null::text as reference");

        $returns = Order::query()->toBase()
            ->join('order_returns', 'order_returns.order_id', '=', 'orders.id')
            ->where('orders.customer_id', $customer->id)
            ->whereNull('order_returns.voided_at')
            ->selectRaw("order_returns.returned_at, 2, 'return'::text, orders.number, order_returns.number, 0::numeric(12,2), order_returns.total, order_returns.id, null::text, null::text");

        $collections = CashCollection::query()->toBase()
            ->join('orders', 'orders.id', '=', 'collections.order_id')
            ->where('orders.customer_id', $customer->id)
            ->whereNull('collections.voided_at')
            ->selectRaw(
                'collections.collected_at, case when collections.kind = ? then 3 else 5 end, '
                .'(case when collections.kind = ? then (case when collections.method = ? then \'refund_to_credit\' else \'refund\' end) '
                .'else (case when collections.method = ? then \'credit_applied\' else \'payment\' end) end)::text, '
                .'orders.number, null::integer, '
                .'case when collections.kind = ? then collections.amount else 0::numeric(12,2) end, '
                .'case when collections.kind = ? then 0::numeric(12,2) else collections.amount end, collections.id, '
                .'collections.method::text, collections.reference::text',
                [CollectionKind::Refund->value, CollectionKind::Refund->value, CollectionMethod::Credit->value, CollectionMethod::Credit->value, CollectionKind::Refund->value, CollectionKind::Refund->value],
            );

        $credits = CustomerCredit::query()->toBase()
            ->leftJoin('order_returns', 'order_returns.id', '=', 'customer_credits.source_return_id')
            ->leftJoin('orders', 'orders.id', '=', 'order_returns.order_id')
            ->where('customer_credits.customer_id', $customer->id)
            ->whereNull('customer_credits.voided_at')
            ->selectRaw("customer_credits.created_at, 4, 'credit_created'::text, orders.number, order_returns.number, 0::numeric(12,2), 0::numeric(12,2), customer_credits.id, null::text, null::text");

        return $delivered->unionAll($returns)->unionAll($collections)->unionAll($credits);
    }
}
