<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Customers\Contracts\RecentOrders;
use App\Modules\Inventory\Contracts\ReservationHolders;
use App\Modules\Messaging\Contracts\ConnectionAlerts;
use App\Modules\Orders\Contracts\CollectsPayments;
use App\Modules\Orders\Contracts\CustomerExposure;
use App\Modules\Orders\Contracts\OrderCollections;
use App\Modules\Orders\Contracts\RefundsPayments;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Policies\OrderPolicy;
use App\Modules\Orders\Policies\OrderReturnPolicy;
use App\Modules\Orders\Services\CustomerRecentOrders;
use App\Modules\Orders\Services\MerchantConnectionAlerts;
use App\Modules\Orders\Services\NullCollectsPayments;
use App\Modules\Orders\Services\NullCustomerExposure;
use App\Modules\Orders\Services\NullOrderCollections;
use App\Modules\Orders\Services\NullRefundsPayments;
use App\Modules\Orders\Services\OrdersHoldingSku;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Orders wiring: the order policy for the storefront and the return policy (BR-R06), and the read contracts earlier modules declared for
 * orders — the orders holding a reservation (Inventory, BR-INV08) and a customer's recent orders (Customers).
 * Orders' own contracts default to their null implementations (no collections recorded, listed or refunded);
 * CollectionsServiceProvider, registered after this one, rebinds them.
 */
final class OrdersServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ReservationHolders::class => OrdersHoldingSku::class,
        RecentOrders::class => CustomerRecentOrders::class,
        ConnectionAlerts::class => MerchantConnectionAlerts::class,
        CollectsPayments::class => NullCollectsPayments::class,
        OrderCollections::class => NullOrderCollections::class,
        RefundsPayments::class => NullRefundsPayments::class,
        CustomerExposure::class => NullCustomerExposure::class,
    ];

    public function boot(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(OrderReturn::class, OrderReturnPolicy::class);
    }
}
