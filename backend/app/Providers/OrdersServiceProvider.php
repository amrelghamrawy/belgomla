<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Customers\Contracts\RecentOrders;
use App\Modules\Inventory\Contracts\ReservationHolders;
use App\Modules\Orders\Contracts\CollectsPayments;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Policies\OrderPolicy;
use App\Modules\Orders\Services\CustomerRecentOrders;
use App\Modules\Orders\Services\NullCollectsPayments;
use App\Modules\Orders\Services\OrdersHoldingSku;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Orders wiring: the order policy for the storefront, and the read contracts earlier modules declared for
 * orders — the orders holding a reservation (Inventory, BR-INV08) and a customer's recent orders (Customers).
 * CollectsPayments records nothing until Collections binds RecordCollection (Task 7).
 */
final class OrdersServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ReservationHolders::class => OrdersHoldingSku::class,
        RecentOrders::class => CustomerRecentOrders::class,
        CollectsPayments::class => NullCollectsPayments::class,
    ];

    public function boot(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);
    }
}
