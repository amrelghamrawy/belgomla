<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\CollectsPayments;
use App\Modules\Orders\Models\Order;

/** Until Collections exists (Task 7) there is nothing to record a collection in. */
final readonly class NullCollectsPayments implements CollectsPayments
{
    public function collectRemaining(Order $order, ?int $actorId): void {}
}
