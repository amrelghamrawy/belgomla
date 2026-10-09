<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Collections\Queries\OutstandingByCustomerQuery;
use App\Modules\Collections\Services\OrderCollectionRecords;
use App\Modules\Collections\Services\RemainingBalanceCollector;
use App\Modules\Customers\Contracts\OutstandingBalances;
use App\Modules\Orders\Contracts\CollectsPayments;
use App\Modules\Orders\Contracts\OrderCollections;
use Illuminate\Support\ServiceProvider;

/**
 * Collections wiring: fills the contracts Orders and Customers declared for collections — "delivered and collected
 * the full amount" (BR-C04), the collections on the order detail, and what each customer owes (D59). Registered
 * after OrdersServiceProvider and CustomersServiceProvider so these bindings replace their null implementations.
 */
final class CollectionsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        CollectsPayments::class => RemainingBalanceCollector::class,
        OrderCollections::class => OrderCollectionRecords::class,
        OutstandingBalances::class => OutstandingByCustomerQuery::class,
    ];
}
