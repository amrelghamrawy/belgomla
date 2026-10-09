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
    ->not->toBeUsedIn(['App\Modules\Audit', 'App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory']);

arch('catalog, pricing and inventory reach tenancy only through its context, concern, hooks, enums and queries')
    ->expect(['App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory'])
    ->not->toUse([
        'App\Modules\Tenancy\Actions',
        'App\Modules\Tenancy\Data',
        'App\Modules\Tenancy\Http',
        'App\Modules\Tenancy\Console',
        'App\Modules\Tenancy\Exceptions',
    ]);

arch('catalog, pricing and inventory reach audit only through the AuditLogger and its enums')
    ->expect(['App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory'])
    ->not->toUse(['App\Modules\Audit\Models', 'App\Modules\Audit\Http']);

arch('tenancy and audit do not depend on catalog, pricing or inventory')
    ->expect(['App\Modules\Tenancy', 'App\Modules\Audit'])
    ->not->toUse(['App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory']);

/*
| Catalog ⇄ Pricing (tasks/2-pricing.md). Pricing never looks up Catalog rows by itself: it receives
| the Product/Sku the controller got from route binding, or reads them through Catalog's queries.
| Catalog prices SKUs only through Pricing's actions, data, services and request concern.
*/

arch('catalog models are used outside catalog only by pricing and inventory')
    ->expect('App\Modules\Catalog\Models')
    ->toOnlyBeUsedIn(['App\Modules\Catalog', 'App\Modules\Pricing', 'App\Modules\Inventory', 'Database\Factories']);

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
        App\Modules\Catalog\Models\Sku::class,
        'Database\Factories',
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
