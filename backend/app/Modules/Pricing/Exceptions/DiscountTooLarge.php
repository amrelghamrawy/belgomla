<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Exceptions;

use App\Support\ApiException;

/**
 * BR-PR08, D85: a manual discount may lower a unit price to 0.01 at most, and an order total to 0.01 at most:
 * `422 discount_too_large`.
 */
final class DiscountTooLarge extends ApiException
{
    public static function unit(string $listPrice): self
    {
        return new self(422, 'discount_too_large', "الخصم أكبر من سعر الوحدة ({$listPrice})", ['list_unit_price' => $listPrice]);
    }

    public static function order(string $itemsTotal): self
    {
        return new self(422, 'discount_too_large', "خصم الطلب أكبر من إجمالي البنود ({$itemsTotal})", ['items_total' => $itemsTotal]);
    }
}
