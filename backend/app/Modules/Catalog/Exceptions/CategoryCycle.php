<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Support\ApiException;

/** BR-P06: a category cannot be its own ancestor. 422 per 08-api-contract.md §6. */
final class CategoryCycle extends ApiException
{
    public static function make(): self
    {
        return new self(422, 'category_cycle', 'لا يمكن نقل القسم داخل نفسه أو داخل أحد أقسامه الفرعية');
    }
}
