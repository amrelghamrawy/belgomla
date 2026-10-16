<?php

declare(strict_types=1);

/*
| Cross-module boundaries (rules/02-architecture.md §1): a module may use another module only
| through its Actions, Queries, Services or Enums. Extend the lists as modules are added.
*/

arch('audit logs are only written through the Audit module')
    ->expect(App\Modules\Audit\Models\AuditLog::class)
    ->toOnlyBeUsedIn(['App\Modules\Audit']);

arch('tenancy models are not used by other modules')
    ->expect('App\Modules\Tenancy\Models')
    ->not->toBeUsedIn(['App\Modules\Audit', 'App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory', 'App\Modules\Messaging', 'App\Modules\Customers', 'App\Modules\Orders', 'App\Modules\Collections', 'App\Modules\Reports']);

arch('catalog, pricing, inventory and messaging reach tenancy only through its context, concern, hooks, enums and queries')
    ->expect(['App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory', 'App\Modules\Messaging'])
    ->not->toUse([
        'App\Modules\Tenancy\Actions',
        'App\Modules\Tenancy\Data',
        'App\Modules\Tenancy\Http',
        'App\Modules\Tenancy\Console',
        'App\Modules\Tenancy\Exceptions',
    ]);

arch('other modules reach audit only through the AuditLogger and its enums')
    ->expect(['App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory', 'App\Modules\Customers', 'App\Modules\Orders', 'App\Modules\Collections', 'App\Modules\Reports'])
    ->not->toUse(['App\Modules\Audit\Models', 'App\Modules\Audit\Http']);

arch('tenancy and audit do not depend on the business modules')
    ->expect(['App\Modules\Tenancy', 'App\Modules\Audit'])
    ->not->toUse(['App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory', 'App\Modules\Messaging', 'App\Modules\Customers', 'App\Modules\Orders', 'App\Modules\Collections', 'App\Modules\Reports']);

/*
| Catalog ⇄ Pricing (tasks/2-pricing.md). Pricing never looks up Catalog rows by itself: it receives
| the Product/Sku the controller got from route binding, or reads them through Catalog's queries.
| Catalog prices SKUs only through Pricing's actions, data, services and request concern.
*/

arch('catalog models are used outside catalog only by pricing, inventory, customers, orders and the low-stock report')
    ->expect('App\Modules\Catalog\Models')
    ->toOnlyBeUsedIn(['App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory', 'App\Modules\Customers', 'App\Modules\Orders', 'App\Modules\Reports', 'Database\Factories', 'Database\Seeders']);

arch('pricing only touches the catalog product and sku it is handed, plus the shared filter')
    ->expect('App\Modules\Pricing')
    ->not->toUse([
        App\Modules\Catalog\Models\Category::class,
        App\Modules\Catalog\Models\Unit::class,
        App\Modules\Catalog\Models\ProductImage::class,
        App\Modules\Catalog\Models\ProductRelation::class,
        App\Modules\Catalog\Models\ProductFieldDefinition::class,
        App\Modules\Catalog\Models\ProductFieldOption::class,
        'App\Modules\Catalog\Models\Scopes',
        'App\Modules\Catalog\Actions',
        'App\Modules\Catalog\Services',
        'App\Modules\Catalog\Exceptions',
        'App\Modules\Catalog\Http',
    ]);

arch('pricing models are used outside pricing only by the Sku tiers relation')
    ->expect('App\Modules\Pricing\Models')
    ->toOnlyBeUsedIn(['App\Modules\Pricing', App\Modules\Catalog\Models\Sku::class, 'Database\Factories']);

arch('catalog reaches pricing only through its actions, data, services and request concern')
    ->expect('App\Modules\Catalog')
    ->not->toUse([
        'App\Modules\Pricing\Queries',
        'App\Modules\Pricing\Exceptions',
        'App\Modules\Pricing\Http',
    ]);

/*
| Catalog ⇄ Inventory (tasks/3-inventory.md). Inventory works on the Sku (and its Product) it is handed
| by route binding or loads by id, and reuses the catalog search. Catalog writes opening stock and
| filters low stock only through Inventory's actions and queries. Stock rows and movements are written
| only by the StockLedger: actions go through it, queries and resources only read.
*/

arch('inventory only touches the catalog product and sku, plus the shared search')
    ->expect('App\Modules\Inventory')
    ->not->toUse([
        App\Modules\Catalog\Models\Category::class,
        App\Modules\Catalog\Models\Unit::class,
        App\Modules\Catalog\Models\ProductImage::class,
        App\Modules\Catalog\Models\ProductRelation::class,
        App\Modules\Catalog\Models\ProductFieldDefinition::class,
        App\Modules\Catalog\Models\ProductFieldOption::class,
        'App\Modules\Catalog\Models\Scopes',
        'App\Modules\Catalog\Actions',
        'App\Modules\Catalog\Services',
        'App\Modules\Catalog\Exceptions',
        'App\Modules\Catalog\Http',
        'App\Modules\Pricing',
    ]);

arch('inventory models are written only by the StockLedger and read by inventory queries and resources')
    ->expect('App\Modules\Inventory\Models')
    ->toOnlyBeUsedIn([
        'App\Modules\Inventory\Models',
        'App\Modules\Inventory\Services',
        'App\Modules\Inventory\Queries',
        'App\Modules\Inventory\Http\Resources',
        App\Modules\Inventory\Actions\AdjustStock::class, // reads the movement the ledger returns, for the audit record
        App\Modules\Orders\Services\CheckoutValidator::class, // reads the stock rows checkout locked through the ledger
        App\Modules\Catalog\Models\Sku::class,
        'Database\Factories',
        'Database\Seeders', // demo data: writes through actions, reads the models they return (rules/02 §1)
    ]);

arch('catalog reaches inventory only through its actions and queries')
    ->expect('App\Modules\Catalog')
    ->not->toUse([
        'App\Modules\Inventory\Services',
        'App\Modules\Inventory\Exceptions',
        'App\Modules\Inventory\Contracts',
        'App\Modules\Inventory\Http',
    ]);

arch('pricing does not depend on inventory')
    ->expect('App\Modules\Pricing')
    ->not->toUse('App\Modules\Inventory');

/*
| Messaging (tasks/4-messaging.md, rules/02 §8). Outbound WhatsApp/SMS go only through Messenger; drivers
| implement MessageDriver; Hypersender classes never leave the module, so moving to the Meta Cloud API
| (D46) is a driver change. Only Messaging talks HTTP to a provider.
*/

arch('only messaging services and jobs use the http client')
    ->expect(Illuminate\Support\Facades\Http::class)
    ->toOnlyBeUsedIn(['App\Modules\Messaging\Services', 'App\Modules\Messaging\Jobs']);

arch('message drivers implement the MessageDriver contract')
    ->expect([
        App\Modules\Messaging\Services\LogDriver::class,
        App\Modules\Messaging\Services\Hypersender\HypersenderWhatsAppDriver::class,
        App\Modules\Messaging\Services\Hypersender\HypersenderSmsDriver::class,
        App\Modules\Messaging\Services\WaGateway\WaGatewayDriver::class,
    ])
    ->toImplement(App\Modules\Messaging\Contracts\MessageDriver::class);

arch('no other module references hypersender classes')
    ->expect('App\Modules\Messaging\Services\Hypersender')
    ->toOnlyBeUsedIn(['App\Modules\Messaging']);

arch('D70 no other module references the store-number gateway')
    ->expect('App\Modules\Messaging\Services\WaGateway')
    ->toOnlyBeUsedIn(['App\Modules\Messaging']);

arch('messaging models are only used inside messaging')
    ->expect('App\Modules\Messaging\Models')
    ->toOnlyBeUsedIn(['App\Modules\Messaging', 'Database\Factories']);

arch('other modules reach messaging only through Messenger, its payload and enums')
    ->expect(['App\Modules\Tenancy', 'App\Modules\Audit', 'App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory', 'App\Modules\Customers', 'App\Modules\Orders', 'App\Modules\Collections', 'App\Modules\Reports'])
    ->not->toUse([
        'App\Modules\Messaging\Actions',
        'App\Modules\Messaging\Contracts',
        'App\Modules\Messaging\Http',
        'App\Modules\Messaging\Jobs',
        'App\Modules\Messaging\Models',
        'App\Modules\Messaging\Queries',
    ]);

arch('messaging does not depend on catalog, pricing or inventory')
    ->expect('App\Modules\Messaging')
    ->not->toUse(['App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory', 'App\Modules\Audit', 'App\Modules\Customers', 'App\Modules\Orders']);

/*
| Customers (tasks/5-customers.md). Customers reads the catalog Sku/Product of a cart line (through Catalog's
| CartSkuQuery and the CartItem relation), prices through Pricing's PriceResolver, sends through Messenger and
| binds sessions with Tenancy's session key. Nothing else depends on Customers until Orders (Task 6).
*/

arch('customers reach tenancy only through its context, concern, queries and the session tenant key')
    ->expect('App\Modules\Customers')
    ->not->toUse([
        'App\Modules\Tenancy\Actions',
        'App\Modules\Tenancy\Data',
        'App\Modules\Tenancy\Console',
        'App\Modules\Tenancy\Exceptions',
        'App\Modules\Tenancy\Http\Controllers',
        'App\Modules\Tenancy\Http\Requests',
        'App\Modules\Tenancy\Http\Resources',
    ]);

arch('customers only touch the catalog product and sku of a cart line')
    ->expect('App\Modules\Customers')
    ->not->toUse([
        App\Modules\Catalog\Models\Category::class,
        App\Modules\Catalog\Models\Unit::class,
        App\Modules\Catalog\Models\ProductImage::class,
        App\Modules\Catalog\Models\ProductRelation::class,
        App\Modules\Catalog\Models\ProductFieldDefinition::class,
        App\Modules\Catalog\Models\ProductFieldOption::class,
        'App\Modules\Catalog\Models\Scopes',
        'App\Modules\Catalog\Actions',
        'App\Modules\Catalog\Services',
        'App\Modules\Catalog\Exceptions',
        'App\Modules\Catalog\Http',
    ]);

arch('customers price only through the pricing services and read stock only through the sku')
    ->expect('App\Modules\Customers')
    ->not->toUse([
        'App\Modules\Pricing\Actions',
        'App\Modules\Pricing\Models',
        'App\Modules\Pricing\Queries',
        'App\Modules\Pricing\Http',
        'App\Modules\Inventory',
    ]);

arch('customers models are used outside customers only by orders and collections')
    ->expect('App\Modules\Customers\Models')
    ->toOnlyBeUsedIn(['App\Modules\Customers', 'App\Modules\Orders', 'App\Modules\Collections', App\Providers\CustomersServiceProvider::class, 'Database\Factories', 'Database\Seeders']);

arch('no earlier module depends on customers')
    ->expect(['App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory'])
    ->not->toUse('App\Modules\Customers');

/*
| Orders (tasks/6-orders.md). Orders reads the cart through Customers' CartQuery and address query, empties it with
| ClearCart, prices through Pricing's PriceResolver, moves stock only through Inventory's StockLedger, and fills the
| contracts Inventory and Customers declared (ReservationHolders, RecentOrders). Catalog and Customers models appear
| only as the typed rows those services hand over (Sku, Product, Customer, CustomerAddress, CartItem).
*/

arch('orders reach tenancy only through its context, concern and queries')
    ->expect('App\Modules\Orders')
    ->not->toUse([
        'App\Modules\Tenancy\Actions',
        'App\Modules\Tenancy\Data',
        'App\Modules\Tenancy\Console',
        'App\Modules\Tenancy\Exceptions',
        'App\Modules\Tenancy\Http',
    ]);

arch('orders only touch the catalog sku and product of an order line')
    ->expect('App\Modules\Orders')
    ->not->toUse([
        App\Modules\Catalog\Models\Category::class,
        App\Modules\Catalog\Models\Unit::class,
        App\Modules\Catalog\Models\ProductImage::class,
        App\Modules\Catalog\Models\ProductRelation::class,
        App\Modules\Catalog\Models\ProductFieldDefinition::class,
        App\Modules\Catalog\Models\ProductFieldOption::class,
        'App\Modules\Catalog\Models\Scopes',
        'App\Modules\Catalog\Actions',
        'App\Modules\Catalog\Queries',
        'App\Modules\Catalog\Services',
        'App\Modules\Catalog\Exceptions',
        'App\Modules\Catalog\Http',
    ]);

arch('orders move stock only through the StockLedger')
    ->expect('App\Modules\Orders')
    ->not->toUse([
        App\Modules\Inventory\Models\InventoryMovement::class,
        'App\Modules\Inventory\Actions',
        'App\Modules\Inventory\Queries',
        'App\Modules\Inventory\Exceptions',
        'App\Modules\Inventory\Http',
    ]);

arch('orders price only through the PriceResolver')
    ->expect('App\Modules\Orders')
    ->not->toUse([
        'App\Modules\Pricing\Actions',
        'App\Modules\Pricing\Models',
        'App\Modules\Pricing\Queries',
        'App\Modules\Pricing\Exceptions',
        'App\Modules\Pricing\Concerns',
        'App\Modules\Pricing\Http',
    ]);

arch('orders use customers only through its queries, cart actions, contracts and the customer, address and cart line')
    ->expect('App\Modules\Orders')
    ->not->toUse([
        App\Modules\Customers\Models\Cart::class,
        App\Modules\Customers\Models\CustomerOtp::class,
        App\Modules\Customers\Models\CustomerInvitation::class,
        'App\Modules\Customers\Models\Scopes',
        'App\Modules\Customers\Data',
        'App\Modules\Customers\Services',
        'App\Modules\Customers\Exceptions',
        'App\Modules\Customers\Http',
    ]);

arch('orders do not depend on messaging')
    ->expect('App\Modules\Orders')
    ->not->toUse('App\Modules\Messaging');

arch('orders models are used outside orders only by collections and reports')
    ->expect('App\Modules\Orders\Models')
    ->toOnlyBeUsedIn([
        'App\Modules\Orders',
        'App\Modules\Collections', // locks the order it records on, sums and lists orders for balances and statements
        'App\Modules\Reports', // reads orders for the overview and sales report
        App\Providers\OrdersServiceProvider::class,
        'Database\Factories',
        'Database\Seeders', // demo data: writes through actions, reads the models they return (rules/02 §1)
    ]);

arch('no earlier module depends on orders')
    ->expect(['App\Modules\Tenancy', 'App\Modules\Audit', 'App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory', 'App\Modules\Messaging', 'App\Modules\Customers', 'App\Support'])
    ->not->toUse('App\Modules\Orders');

arch('orders fill the reservation holders contract of inventory')
    ->expect(App\Modules\Orders\Services\OrdersHoldingSku::class)
    ->toImplement(App\Modules\Inventory\Contracts\ReservationHolders::class);

arch('orders fill the recent orders contract of customers')
    ->expect(App\Modules\Orders\Services\CustomerRecentOrders::class)
    ->toImplement(App\Modules\Customers\Contracts\RecentOrders::class);

/*
| Collections (tasks/7-collections-reports.md). Collections locks and reads the Order it records on, stores the
| balance through Orders' ApplyCollectedAmount, and fills the contracts Orders and Customers declared
| (CollectsPayments, OrderCollections, OutstandingBalances). Nothing earlier depends on it.
*/

arch('collection models are only used inside collections')
    ->expect('App\Modules\Collections\Models')
    ->toOnlyBeUsedIn(['App\Modules\Collections', 'Database\Factories']);

arch('collections use orders only through the order, its enums, contracts and ApplyCollectedAmount')
    ->expect('App\Modules\Collections')
    ->not->toUse([
        App\Modules\Orders\Models\OrderItem::class,
        App\Modules\Orders\Models\OrderStatusHistory::class,
        App\Modules\Orders\Models\MerchantNotification::class,
        App\Modules\Orders\Models\TenantOrderCounter::class,
        App\Modules\Orders\Actions\TransitionOrder::class,
        App\Modules\Orders\Actions\CancelOrder::class,
        App\Modules\Orders\Actions\ApplyTransition::class,
        'App\Modules\Orders\Data',
        'App\Modules\Orders\Exceptions',
        'App\Modules\Orders\Http',
        'App\Modules\Orders\Policies',
        'App\Modules\Orders\Queries',
        'App\Modules\Orders\Services',
    ]);

arch('collections use customers only through the customer row and the outstanding contract')
    ->expect('App\Modules\Collections')
    ->not->toUse([
        App\Modules\Customers\Models\Cart::class,
        App\Modules\Customers\Models\CartItem::class,
        App\Modules\Customers\Models\CustomerAddress::class,
        App\Modules\Customers\Models\CustomerOtp::class,
        App\Modules\Customers\Models\CustomerInvitation::class,
        'App\Modules\Customers\Actions',
        'App\Modules\Customers\Data',
        'App\Modules\Customers\Services',
        'App\Modules\Customers\Exceptions',
        'App\Modules\Customers\Http',
    ]);

arch('collections do not depend on catalog, pricing, inventory, messaging or reports')
    ->expect('App\Modules\Collections')
    ->not->toUse(['App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory', 'App\Modules\Messaging', 'App\Modules\Reports']);

arch('no earlier module depends on collections')
    ->expect(['App\Modules\Tenancy', 'App\Modules\Audit', 'App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory', 'App\Modules\Messaging', 'App\Modules\Customers', 'App\Modules\Orders', 'App\Support'])
    ->not->toUse('App\Modules\Collections');

arch('collections fill the collects payments contract of orders')
    ->expect(App\Modules\Collections\Services\RemainingBalanceCollector::class)
    ->toImplement(App\Modules\Orders\Contracts\CollectsPayments::class);

arch('collections fill the order collections contract of orders')
    ->expect(App\Modules\Collections\Services\OrderCollectionRecords::class)
    ->toImplement(App\Modules\Orders\Contracts\OrderCollections::class);

arch('collections fill the outstanding balances contract of customers')
    ->expect(App\Modules\Collections\Queries\OutstandingByCustomerQuery::class)
    ->toImplement(App\Modules\Customers\Contracts\OutstandingBalances::class);

/*
| Reports (tasks/7-collections-reports.md, D5) are read-only: queries, their input and resources. They read orders,
| reuse Collections' outstanding query and Inventory's low-stock query, and never write: no actions, no writing
| services, no DB facade (so no transactions or raw writes). Nothing depends on Reports.
*/

arch('reports write nothing')
    ->expect('App\Modules\Reports')
    ->not->toUse([
        Illuminate\Support\Facades\DB::class,
        'App\Modules\Tenancy\Actions',
        'App\Modules\Catalog\Actions',
        'App\Modules\Pricing\Actions',
        'App\Modules\Inventory\Actions',
        'App\Modules\Inventory\Services',
        'App\Modules\Messaging',
        'App\Modules\Customers\Actions',
        'App\Modules\Orders\Actions',
        'App\Modules\Collections\Actions',
        'App\Modules\Collections\Services',
        'App\Modules\Audit\Services',
    ]);

arch('reports read other modules only through orders, their queries and enums')
    ->expect('App\Modules\Reports')
    ->not->toUse([
        'App\Modules\Inventory\Models',
        'App\Modules\Customers\Models',
        'App\Modules\Collections\Models',
        'App\Modules\Orders\Http',
        'App\Modules\Collections\Http',
        'App\Modules\Inventory\Http',
    ]);

arch('no module depends on reports')
    ->expect(['App\Modules\Tenancy', 'App\Modules\Audit', 'App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory', 'App\Modules\Messaging', 'App\Modules\Customers', 'App\Modules\Orders', 'App\Modules\Collections', 'App\Support'])
    ->not->toUse('App\Modules\Reports');

test('reports contain only queries, their input, enums and http', function (): void {
    $folders = array_map(basename(...), glob(dirname(__DIR__, 2).'/app/Modules/Reports/*', GLOB_ONLYDIR) ?: []);

    expect($folders)->toBe(['Data', 'Enums', 'Http', 'Queries']);
});

/*
| Platform (tasks/8 OpenAPI, tasks/11 monitoring) is operator tooling. It reads tenants through Tenancy's queries
| and context (the Tenant model appears only as the row a query returns), the SMS heartbeat through Messaging's query, and alerts only through Messenger.
*/

arch('platform reaches other modules only through their queries, services, data, enums and the tenant context')
    ->expect('App\Modules\Platform')
    ->not->toUse([
        App\Modules\Tenancy\Models\TenantUser::class,
        App\Modules\Tenancy\Models\TenantSetting::class,
        'App\Modules\Tenancy\Actions',
        'App\Modules\Tenancy\Http',
        'App\Modules\Messaging\Models',
        'App\Modules\Messaging\Actions',
        'App\Modules\Messaging\Contracts',
        'App\Modules\Messaging\Http',
        'App\Modules\Messaging\Jobs',
        'App\Modules\Messaging\Services\Hypersender',
    ]);

arch('no business module depends on platform')
    ->expect(['App\Modules\Tenancy', 'App\Modules\Audit', 'App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory', 'App\Modules\Messaging', 'App\Modules\Customers', 'App\Modules\Orders', 'App\Modules\Collections', 'App\Modules\Reports'])
    ->not->toUse('App\Modules\Platform');
