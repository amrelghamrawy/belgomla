<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Exceptions;

use App\Support\ApiException;

/** Input that passed the request rules but not the data it meets. Rendered exactly like a validation failure. */
final class InvalidPricingInput extends ApiException
{
    public static function field(string $field, string $message): self
    {
        return new self(422, 'validation_failed', 'بعض البيانات غير صحيحة', [$field => [$message]]);
    }

    /** BR-PR01: a % so close to 100 that the price of a tiny cover price rounds to zero. */
    public static function basePriceNotPositive(): self
    {
        return self::field('base_price', 'السعر الأساسي الناتج يجب أن يكون أكبر من صفر');
    }

    /** Same guard as bulk status (BR-P12): an empty filter would reprice the whole catalog. */
    public static function emptyFilter(): self
    {
        return self::field('filter', 'حدد شرطًا واحدًا على الأقل');
    }
}
