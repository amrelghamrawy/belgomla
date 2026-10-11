<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Exceptions;

use App\Modules\Catalog\Enums\CategoryKind;
use App\Support\ApiException;

/**
 * BR-PR10: a price was given as a % off the reference price, but the SKU has none. `422 cover_price_required`.
 * The message names the price as the product's section does: سعر الغلاف for books, سعر القطاعي otherwise (D68).
 */
final class MissingCoverPrice extends ApiException
{
    public static function for(CategoryKind $kind): self
    {
        return new self(422, 'cover_price_required', "أدخل {$kind->referencePriceLabel()} أولًا لحساب السعر بنسبة الخصم");
    }
}
