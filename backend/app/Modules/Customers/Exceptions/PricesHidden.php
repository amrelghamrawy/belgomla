<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use App\Support\ApiException;

/** D1 / BR-CU04: with `require_login_for_prices` on, a guest is told to log in before adding to the cart (D53). */
final class PricesHidden extends ApiException
{
    public static function make(): self
    {
        return new self(403, 'prices_hidden', 'سجّل الدخول لعرض الأسعار والإضافة إلى السلة');
    }
}
