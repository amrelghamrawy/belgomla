<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Exceptions;

use App\Support\ApiException;

/**
 * The tiers of a SKU break BR-PR05 (duplicate threshold, price not above zero) or D28 (a tier priced
 * above the base price). `422 invalid_tiers`, one message per problem in `details.tiers`.
 */
final class InvalidTiers extends ApiException
{
    /**
     * @param  list<string>  $problems
     */
    public static function withProblems(array $problems): self
    {
        return new self(422, 'invalid_tiers', 'شرائح الأسعار غير صحيحة', ['tiers' => $problems]);
    }

    public static function duplicateThreshold(int $minQuantity): string
    {
        return "الحد الأدنى {$minQuantity} مكرر في أكثر من شريحة";
    }

    public static function notPositive(int $minQuantity): string
    {
        return "سعر شريحة {$minQuantity} يجب أن يكون أكبر من صفر";
    }

    public static function aboveBasePrice(int $minQuantity): string
    {
        return "سعر شريحة {$minQuantity} أعلى من السعر الأساسي";
    }
}
