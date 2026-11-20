<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Exceptions;

use App\Support\ApiException;

/**
 * BR-PR10: a price was given as a % off the reference price, but the SKU has none. `422 cover_price_required`.
 * The message names the price as the product's section does (`reference_price_label`, D109 d): سعر الغلاف for
 * كتب خارجية, سعر القطاعي by default.
 */
final class MissingCoverPrice extends ApiException
{
    public static function for(string $referencePriceLabel): self
    {
        return new self(422, 'cover_price_required', "أدخل {$referencePriceLabel} أولًا لحساب السعر بنسبة الخصم");
    }
}
