<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Customers\Contracts\OutstandingBalances;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Policies\CustomerAddressPolicy;
use App\Modules\Customers\Services\NullOutstandingBalances;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Customers wiring: the address policy (BR-CU03), and the read contract Collections fills later
 * (OutstandingBalances, Task 7); until then customers owe nothing. RecentOrders is bound by Orders
 * (OrdersServiceProvider).
 */
final class CustomersServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        OutstandingBalances::class => NullOutstandingBalances::class,
    ];

    public function boot(): void
    {
        Gate::policy(CustomerAddress::class, CustomerAddressPolicy::class);
    }
}
