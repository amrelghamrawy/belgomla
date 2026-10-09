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
    ->not->toBeUsedIn(['App\Modules\Audit', 'App\Modules\Catalog', 'App\Modules\Pricing']);

arch('catalog and pricing reach tenancy only through its context, concern, hooks, enums and queries')
    ->expect(['App\Modules\Catalog', 'App\Modules\Pricing'])
    ->not->toUse([
        'App\Modules\Tenancy\Actions',
        'App\Modules\Tenancy\Data',
        'App\Modules\Tenancy\Http',
        'App\Modules\Tenancy\Console',
        'App\Modules\Tenancy\Exceptions',
    ]);

arch('catalog and pricing reach audit only through the AuditLogger')
    ->expect(['App\Modules\Catalog', 'App\Modules\Pricing'])
    ->not->toUse(['App\Modules\Audit\Models', 'App\Modules\Audit\Http']);

arch('tenancy and audit do not depend on catalog or pricing')
    ->expect(['App\Modules\Tenancy', 'App\Modules\Audit'])
    ->not->toUse(['App\Modules\Catalog', 'App\Modules\Pricing']);

/*
| Catalog ⇄ Pricing (tasks/2-pricing.md). Pricing never looks up Catalog rows by itself: it receives
| the Product/Sku the controller got from route binding, or reads them through Catalog's queries.
| Catalog prices SKUs only through Pricing's actions, data, services and request concern.
*/

arch('catalog models are used outside catalog only by pricing')
    ->expect('App\Modules\Catalog\Models')
    ->toOnlyBeUsedIn(['App\Modules\Catalog', 'App\Modules\Pricing', 'Database\Factories']);

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
