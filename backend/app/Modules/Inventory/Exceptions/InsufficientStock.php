<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Support\ApiException;

/**
 * BR-INV01: no backorders. A reservation larger than Available is refused with `422 insufficient_stock`
 * and the SKU in `items` (08-api-contract.md §0); checkout (Task 6) collects these into `checkout_failed`.
 */
final class InsufficientStock extends ApiException
{
    public static function for(int $skuId, int $available): self
    {
        return new self(
            422,
            'insufficient_stock',
            'الكمية المطلوبة أكبر من المتاح في المخزون',
            null,
            [['sku_id' => $skuId, 'reason' => 'insufficient_stock', 'available' => $available]],
        );
    }
}
