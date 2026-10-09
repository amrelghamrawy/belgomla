<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Support\ApiException;

/**
 * A cart line that cannot be ordered: `422 cart_line_invalid`, with the SKU in `items` in the same shape as
 * checkout failures (08-api-contract.md §0), so the storefront handles both alike.
 */
final class CartLineInvalid extends ApiException
{
    /** BR-MOQ01 */
    public static function belowMoq(int $skuId, int $moq): self
    {
        return new self(422, 'cart_line_invalid', "الحد الأدنى للطلب {$moq}", null, [['sku_id' => $skuId, 'reason' => 'below_moq', 'moq' => $moq]]);
    }

    /** BR-INV01: `available` is in selling units, what the customer can still add. */
    public static function insufficientStock(int $skuId, int $available): self
    {
        return new self(422, 'cart_line_invalid', 'الكمية غير متوفرة', null, [['sku_id' => $skuId, 'reason' => 'insufficient_stock', 'available' => $available]]);
    }

    /** BR-P04, D21: inactive and coming-soon products cannot be added. */
    public static function productInactive(int $skuId): self
    {
        return new self(422, 'cart_line_invalid', 'المنتج غير متاح للطلب', null, [['sku_id' => $skuId, 'reason' => 'product_inactive']]);
    }
}
