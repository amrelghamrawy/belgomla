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
    ->not->toBeUsedIn(['App\Modules\Audit', 'App\Modules\Catalog']);

arch('catalog reaches tenancy only through its context, concern, hooks, enums and queries')
    ->expect('App\Modules\Catalog')
    ->not->toUse([
        'App\Modules\Tenancy\Actions',
        'App\Modules\Tenancy\Data',
        'App\Modules\Tenancy\Http',
        'App\Modules\Tenancy\Console',
        'App\Modules\Tenancy\Exceptions',
    ]);

arch('catalog reaches audit only through the AuditLogger')
    ->expect('App\Modules\Catalog')
    ->not->toUse(['App\Modules\Audit\Models', 'App\Modules\Audit\Http']);

arch('tenancy and audit do not depend on catalog')
    ->expect(['App\Modules\Tenancy', 'App\Modules\Audit'])
    ->not->toUse('App\Modules\Catalog');

arch('catalog models are not used by other modules')
    ->expect('App\Modules\Catalog\Models')
    ->toOnlyBeUsedIn(['App\Modules\Catalog', 'Database\Factories']);
