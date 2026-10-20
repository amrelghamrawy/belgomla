<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Pricing\Enums\DiscountType;

/** One order line's manual discount per unit (BR-PR08, D85): EGP or % off its list price; no type = no discount. */
final readonly class LineDiscountData
{
    public function __construct(
        public int $orderItemId,
        public ?DiscountType $type = null,
        public ?string $value = null,
    ) {}
}
