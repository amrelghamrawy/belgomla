<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Support\ApiException;

/**
 * BR-INV08: On Hand cannot go below Reserved. `422 inventory_below_reserved` with the reserved quantity,
 * the minimum allowed On Hand and the open orders holding it (08-api-contract.md §7).
 */
final class BelowReserved extends ApiException
{
    /**
     * @param  list<array{number: int, status: string, quantity_base: int}>  $orders
     */
    public static function for(int $newOnHand, int $reserved, array $orders): self
    {
        $held = $orders === []
            ? 'لطلبات قائمة'
            : 'للطلبات: '.implode('، ', array_map(fn (array $order): string => '#'.$order['number'], $orders));

        return new self(
            422,
            'inventory_below_reserved',
            "لا يمكن تقليل المخزون إلى {$newOnHand} لأن {$reserved} وحدة محجوزة {$held}. ألغِ الطلبات أو سلّمها أولًا.",
            ['reserved' => $reserved, 'min_allowed' => $reserved, 'orders' => $orders],
        );
    }

    public function reserved(): int
    {
        $reserved = $this->details['reserved'] ?? 0;

        return is_int($reserved) ? $reserved : 0;
    }
}
