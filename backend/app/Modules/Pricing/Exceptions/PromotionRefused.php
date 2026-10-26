<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Exceptions;

use App\Modules\Pricing\Enums\PromotionStatus;
use App\Support\ApiException;

/** BR-PR15 (V2 Task 12, D90): why a promotion was not saved, changed or cancelled. */
final class PromotionRefused extends ApiException
{
    /** D90 f: only a draft or a promotion that has not started can be edited; an ended or cancelled one cannot be cancelled. */
    public static function locked(PromotionStatus $status): self
    {
        return new self(409, 'promotion_locked', "لا يمكن تغيير عرض حالته «{$status->label()}»", ['status' => $status->value]);
    }

    /** D90 d: the filter or the list selects no SKU. */
    public static function nothingSelected(string $field): self
    {
        return new self(422, 'validation_failed', 'بعض البيانات غير صحيحة', [$field => ['لا توجد أصناف ضمن هذا الاختيار']]);
    }

    public static function unknownSkus(): self
    {
        return new self(422, 'validation_failed', 'بعض البيانات غير صحيحة', ['sku_ids' => ['بعض الأصناف غير موجودة']]);
    }

    /** D90 e: the end comes after the start, and in the future. */
    public static function badWindow(): self
    {
        return new self(422, 'validation_failed', 'بعض البيانات غير صحيحة', ['ends_at' => ['يجب أن ينتهي العرض بعد بدايته وبعد الآن']]);
    }
}
