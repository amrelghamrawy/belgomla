<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Customers\Contracts\OutstandingBalances;
use App\Modules\Customers\Contracts\RecentOrders;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Policies\CustomerAddressPolicy;
use App\Modules\Customers\Services\NullOutstandingBalances;
use App\Modules\Customers\Services\NullRecentOrders;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Customers wiring: the address policy (BR-CU03), and the two read contracts other modules fill later —
 * RecentOrders by Orders (Task 6), OutstandingBalances by Collections (Task 7). Until then customers have
 * no orders and owe nothing.
 */
final class CustomersServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        OutstandingBalances::class => NullOutstandingBalances::class,
        RecentOrders::class => NullRecentOrders::class,
    ];

    public function boot(): void
    {
        Gate::policy(CustomerAddress::class, CustomerAddressPolicy::class);
    }
}
