<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Support\ApiException;

/**
 * Receiving goods or setting the cost of a SKU (BR-INV09, D96) refused: a bundle has no stock of its own (D93 b,
 * `409 is_bundle`), a unit that is not one of the SKU's, or a receipt above the stock ceiling (`422 validation_failed`
 * on the field, like a FormRequest error).
 */
final class CostingRefused extends ApiException
{
    public static function isBundle(): self
    {
        return new self(409, 'is_bundle', 'هذا المنتج باقة وليس له مخزون: استلم مكوناته');
    }

    public static function unknownUnit(): self
    {
        return new self(422, 'validation_failed', 'البيانات غير صحيحة', ['sku_unit_id' => ['الوحدة ليست من وحدات هذا الصنف']]);
    }

    public static function tooMuch(int $max): self
    {
        return new self(422, 'validation_failed', 'البيانات غير صحيحة', ['quantity' => ["الكمية بالوحدة الأساسية أكبر من الحد المسموح ({$max})"]]);
    }
}
