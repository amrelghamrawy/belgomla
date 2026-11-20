<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Customers\Contracts\OutstandingBalances;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Policies\CustomerAddressPolicy;
use App\Modules\Customers\Queries\PricingContextQuery;
use App\Modules\Customers\Services\NullOutstandingBalances;
use App\Modules\Pricing\Contracts\CustomerPricing;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Customers wiring: the address policy (BR-CU03), and the read contract Collections fills (OutstandingBalances,
 * rebound in CollectionsServiceProvider); without it customers owe nothing. RecentOrders is bound by Orders
 * (OrdersServiceProvider). Customers fill Pricing's CustomerPricing contract (PricingContextQuery, BR-PR14), so the
 * storefront's product list, product page and price list show a customer their own prices.
 */
final class CustomersServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        OutstandingBalances::class => NullOutstandingBalances::class,
        CustomerPricing::class => PricingContextQuery::class,
    ];

    public function boot(): void
    {
        Gate::policy(CustomerAddress::class, CustomerAddressPolicy::class);
    }
}
