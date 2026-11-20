<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\ConnectionAlerts;

/** ConnectionAlerts without the Orders module: nobody to tell. */
final readonly class NullConnectionAlerts implements ConnectionAlerts
{
    public function dropped(?string $phone, string $reason): void {}
}
