<?php

declare(strict_types=1);

namespace App\Modules\Orders\Data;

use App\Modules\Orders\Enums\OrderStatus;

/**
 * What a status change does (07-domain-model.md §11), decided by OrderStateMachine and carried out by the
 * actions in one transaction: the new status, the timestamp columns to set now, and the stock side effect —
 * release the reservation on cancel (BR-INV04) or deduct it on delivery (BR-INV05).
 */
final readonly class TransitionPlan
{
    /**
     * @param  list<string>  $timestamps  order columns set to now
     */
    public function __construct(
        public OrderStatus $from,
        public OrderStatus $to,
        public array $timestamps,
        public bool $releasesStock = false,
        public bool $deductsStock = false,
    ) {}
}
