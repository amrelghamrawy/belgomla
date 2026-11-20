<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Models\Order;

/**
 * What EditOrderLines leaves (BR-O12): the order, and the minimum order value shortfall of its new total, which the
 * merchant may go under but is warned about (BR-MOV02, `meta.below_mov`).
 */
final readonly class EditedOrder
{
    /**
     * @param  array{required: string, current: string}|null  $belowMov
     */
    public function __construct(
        public Order $order,
        public ?array $belowMov = null,
    ) {}
}
