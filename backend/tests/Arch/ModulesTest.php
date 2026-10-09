<?php

declare(strict_types=1);

use App\Support\ApiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Resources\Json\JsonResource;

/*
|--------------------------------------------------------------------------
| rules/02-architecture.md §2–§3, enforced per folder
|--------------------------------------------------------------------------
*/

arch('actions are final readonly with a single handle() entry point')
    ->expect(moduleNamespaces('Actions'))
    ->toBeFinal()
    ->toBeReadonly()
    ->toHaveMethod('handle')
    ->toExtendNothing();

arch('queries are final readonly and extend nothing')
    ->expect(moduleNamespaces('Queries'))
    ->toBeFinal()
    ->toBeReadonly()
    ->toHaveSuffix('Query')
    ->toExtendNothing();

arch('services are final and extend nothing')
    ->expect(moduleNamespaces('Services'))
    ->toBeFinal()
    ->toExtendNothing()
    ->not->toHaveSuffix('Service')
    ->not->toHaveSuffix('Helper')
    ->not->toHaveSuffix('Manager');

arch('data classes are final readonly value objects')
    ->expect(moduleNamespaces('Data'))
    ->toBeFinal()
    ->toBeReadonly()
    ->toHaveSuffix('Data')
    ->ignoring(App\Modules\Tenancy\Data\TenantUserChanges::class)
    ->toExtendNothing();

arch('enums are backed string enums with labels')
    ->expect(moduleNamespaces('Enums'))
    ->toBeStringBackedEnums()
    ->toHaveMethod('label')
    ->toHaveMethod('toArray');

arch('module exceptions are final and extend ApiException')
    ->expect(moduleNamespaces('Exceptions'))
    ->toBeFinal()
    ->toExtend(ApiException::class);

arch('models are final, extend Eloquent and declare casts()')
    ->expect(moduleNamespaces('Models'))
    ->toBeFinal()
    ->toExtend(Model::class)
    ->toHaveMethod('casts');

arch('models never depend on the write side')
    ->expect(moduleNamespaces('Models'))
    ->not->toUse(moduleNamespaces('Actions'))
    ->not->toUse(moduleNamespaces('Services'))
    ->not->toUse(moduleNamespaces('Http'));

arch('resources are final JsonResources')
    ->expect(moduleNamespaces('Http\Resources'))
    ->toBeFinal()
    ->toExtend(JsonResource::class)
    ->toHaveSuffix('Resource')
    ->toHaveMethod('toArray');

arch('requests are final FormRequests with rules()')
    ->expect(moduleNamespaces('Http\Requests'))
    ->toBeFinal()
    ->toExtend(FormRequest::class)
    ->toHaveSuffix('Request')
    ->toHaveMethod('rules');

arch('controllers are final readonly, extend nothing, and are only referenced from routes')
    ->expect(moduleNamespaces('Http\Controllers'))
    ->toBeFinal()
    ->toBeReadonly()
    ->toHaveSuffix('Controller')
    ->toExtendNothing()
    ->not->toBeUsed();

arch('controllers never touch the database or transactions directly')
    ->expect(moduleNamespaces('Http\Controllers'))
    ->not->toUse([Illuminate\Support\Facades\DB::class, Illuminate\Database\Eloquent\Builder::class]);

arch('middleware is final with handle()')
    ->expect(moduleNamespaces('Http\Middleware'))
    ->toBeFinal()
    ->toHaveMethod('handle');

arch('policies are final readonly and extend nothing')
    ->expect(moduleNamespaces('Policies'))
    ->toBeFinal()
    ->toBeReadonly()
    ->toHaveSuffix('Policy')
    ->toExtendNothing();

arch('jobs are final and queued')
    ->expect(moduleNamespaces('Jobs'))
    ->toBeFinal()
    ->toImplement(ShouldQueue::class)
    ->toHaveMethod('handle');

arch('rules are final readonly validation rules')
    ->expect(moduleNamespaces('Rules'))
    ->toBeFinal()
    ->toBeReadonly()
    ->toImplement(ValidationRule::class);

arch('commands end with Command and have handle()')
    ->expect(moduleNamespaces('Console'))
    ->toHaveSuffix('Command')
    ->toHaveMethod('handle');

arch('http code never bypasses the tenant scope')
    ->expect('withoutTenantScope')
    ->not->toBeUsedIn(moduleNamespaces('Http'));

arch('the request object never reaches actions, queries or services')
    ->expect(Illuminate\Http\Request::class)
    ->not->toBeUsedIn(array_merge(moduleNamespaces('Actions'), moduleNamespaces('Queries')));

arch('support classes are final and framework-light')
    ->expect('App\Support')
    ->classes()
    ->toBeFinal()
    ->ignoring([App\Support\Http\PaginatedRequest::class, ApiException::class]);
