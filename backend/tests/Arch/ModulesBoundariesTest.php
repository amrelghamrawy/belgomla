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
    ->not->toBeUsedIn(['App\Modules\Audit']);
