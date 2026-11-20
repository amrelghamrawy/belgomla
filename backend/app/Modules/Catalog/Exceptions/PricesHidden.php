<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Support\ApiException;

/**
 * D1 / BR-P13: the store hides prices from guests (`require_login_for_prices`), so a guest cannot download the price
 * list file. Same code and message as the cart's guard (Customers), which Catalog may not use.
 */
final class PricesHidden extends ApiException
{
    public static function forGuest(): self
    {
        return new self(403, 'prices_hidden', 'سجّل الدخول لعرض الأسعار والإضافة إلى السلة');
    }
}
