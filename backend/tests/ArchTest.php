<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security()->ignoring('assert');

// The Laravel preset assumes the stock app/Http, app/Console layout. BelGomla is organised by
// module (app/Modules/<Module>/...), so the equivalent rules live in tests/Arch/*.php.

arch('strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('no debugging or sleeping helpers')
    ->expect(['dd', 'dump', 'ray', 'die', 'var_dump', 'sleep', 'usleep'])
    ->not->toBeUsed();

arch('env() is only read from config files')
    ->expect('env')
    ->not->toBeUsed();

arch('no stock laravel folders are used')
    ->expect(['App\Http', 'App\Console', 'App\Models'])
    ->not->toBeUsed();
