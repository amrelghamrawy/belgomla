<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use Carbon\CarbonImmutable;

/**
 * What the dashboard order shape needs to judge a pending order: placed before `staleBefore` = stale (BR-INV07,
 * D10); with `expiryHours` set, it cancels itself that many hours after it was placed (D81).
 */
final readonly class PendingClockData
{
    public function __construct(
        public CarbonImmutable $staleBefore,
        public ?int $expiryHours,
    ) {}
}
