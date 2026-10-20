<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Support\ApiException;

/** BR-O14, D88: pre-orders are converted only once their product is active (and stocked): `409 product_not_active`. */
final class PreordersNotConvertible extends ApiException
{
    public static function productNotActive(ProductStatus $status): self
    {
        return new self(409, 'product_not_active', "فعّل المنتج وأضف مخزونه قبل تحويل الحجوزات (حالته الآن «{$status->label()}»)", ['status' => $status->value]);
    }
}
