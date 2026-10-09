<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Support\ApiException;

/** A unit cannot be archived while any SKU sells or stocks in it (08-api-contract.md §6). */
final class UnitInUse extends ApiException
{
    public static function bySkus(int $count): self
    {
        return new self(409, 'in_use', 'لا يمكن أرشفة وحدة مستخدمة في أصناف', ['skus' => $count]);
    }
}
