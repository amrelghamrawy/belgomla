<?php

declare(strict_types=1);

namespace App\Modules\Collections\Queries;

use App\Modules\Collections\Models\CashCollection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * V2 Task 16: the tenant's live collections (BR-C02) collected between two instants, one row each — `at`
 * (collected_at), `kind` (payment or refund, BR-R04), `method` (cash or customer credit, BR-C07), `amount` (always
 * positive) and the order's customer's current `customer_group_id` — for the collections report. Voided rows are out.
 */
final readonly class CollectionFlowQuery
{
    public function between(CarbonImmutable $start, CarbonImmutable $end): QueryBuilder
    {
        return CashCollection::query()->toBase()
            ->join('orders', 'orders.id', '=', 'collections.order_id')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->whereNull('collections.voided_at')
            ->whereBetween('collections.collected_at', [$start, $end])
            ->select(['collections.collected_at as at', 'collections.kind', 'collections.method', 'collections.amount', 'customers.customer_group_id']);
    }
}
