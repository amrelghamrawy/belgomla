<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Exceptions;

use App\Support\ApiException;

/** BR-PR10: a price was given as a % off the cover price, but the SKU has no cover price. `422 cover_price_required`. */
final class MissingCoverPrice extends ApiException
{
    public static function make(): self
    {
        return new self(422, 'cover_price_required', 'أدخل سعر الغلاف أولًا لحساب السعر بنسبة الخصم');
    }
}
