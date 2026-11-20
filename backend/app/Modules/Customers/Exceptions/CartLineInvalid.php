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
    public static function belowMoq(int $skuId, int $skuUnitId, int $moq): self
    {
        return new self(422, 'cart_line_invalid', "الحد الأدنى للطلب {$moq}", null, [['sku_id' => $skuId, 'sku_unit_id' => $skuUnitId, 'reason' => 'below_moq', 'moq' => $moq]]);
    }

    /** BR-INV01: `available` is in the line's unit, what the customer can still add next to the SKU's other units in the cart (D91). */
    public static function insufficientStock(int $skuId, int $skuUnitId, int $available): self
    {
        return new self(422, 'cart_line_invalid', 'الكمية غير متوفرة', null, [['sku_id' => $skuId, 'sku_unit_id' => $skuUnitId, 'reason' => 'insufficient_stock', 'available' => $available]]);
    }

    /** BR-O14, D88: only a Coming Soon product with pre-orders on can be pre-ordered. */
    public static function preorderUnavailable(int $skuId, int $skuUnitId): self
    {
        return new self(422, 'cart_line_invalid', 'الحجز المسبق غير متاح لهذا المنتج', null, [['sku_id' => $skuId, 'sku_unit_id' => $skuUnitId, 'reason' => 'preorder_unavailable']]);
    }

    /** BR-P04, D21: inactive and coming-soon products cannot be added, nor a unit no longer sold (D91). */
    public static function productInactive(int $skuId, int $skuUnitId): self
    {
        return new self(422, 'cart_line_invalid', 'المنتج غير متاح للطلب', null, [['sku_id' => $skuId, 'sku_unit_id' => $skuUnitId, 'reason' => 'product_inactive']]);
    }
}
