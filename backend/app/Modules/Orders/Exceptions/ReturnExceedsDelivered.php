<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Support\ApiException;

/**
 * BR-R02: a line cannot bring back more than was delivered and not returned yet: `422 return_exceeds_delivered`,
 * one item per offending line `{ order_item_id, requested, returnable }` (selling units).
 */
final class ReturnExceedsDelivered extends ApiException
{
    /**
     * @param  list<array{order_item_id: int, requested: int, returnable: int}>  $items
     */
    public static function items(array $items): self
    {
        return new self(
            422,
            'return_exceeds_delivered',
            'الكمية المرتجعة أكبر من الكمية المسلّمة المتبقية',
            null,
            array_map(fn (array $item): array => [...$item, 'reason' => 'return_exceeds_delivered'], $items),
        );
    }
}
