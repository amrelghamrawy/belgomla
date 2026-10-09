<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;

arch('models extend Eloquent and declare casts()')
    ->expect(moduleNamespaces('Models'))
    ->toExtend(Model::class)
    ->toHaveMethod('casts');

arch('models are not referenced from another module\'s http layer')
    ->expect('App\Modules\Audit\Models')
    ->not->toBeUsedIn(['App\Modules\Tenancy\Http']);
