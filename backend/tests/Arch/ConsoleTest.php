<?php

declare(strict_types=1);

use Illuminate\Console\Command;

arch('commands')
    ->expect(moduleNamespaces('Console'))
    ->toExtend(Command::class)
    ->toHaveSuffix('Command')
    ->toHaveMethod('handle');
