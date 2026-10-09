<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

arch('controllers extend nothing and are only referenced from routes')
    ->expect(moduleNamespaces('Http\Controllers'))
    ->toExtendNothing()
    ->not->toBeUsed();

arch('middleware exposes handle() and works on the request')
    ->expect(moduleNamespaces('Http\Middleware'))
    ->toHaveMethod('handle')
    ->toUse(Request::class);

arch('form requests declare rules()')
    ->expect(moduleNamespaces('Http\Requests'))
    ->toExtend(FormRequest::class)
    ->toHaveMethod('rules');

arch('http code never bypasses the tenant scope')
    ->expect('withoutTenantScope')
    ->not->toBeUsedIn(moduleNamespaces('Http'));
