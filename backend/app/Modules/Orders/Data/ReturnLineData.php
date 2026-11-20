<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

/** One returned line: the order line and how many selling units came back. */
final readonly class ReturnLineData
{
    public function __construct(
        public int $orderItemId,
        public int $quantity,
    ) {}
}
