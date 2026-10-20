<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Support\ApiException;

/**
 * V2 Task 06: one row of a catalog file failed CatalogRowMapper's checks (a reference it cannot resolve, a value
 * the product request rules refuse). `details` is keyed by the file's column keys (`base_price`, `field:grade`,
 * `tier_2_min`), so the preview can point at the cell. Never reaches the API as such: the import lists it per row.
 */
final class InvalidImportRow extends ApiException
{
    /**
     * @param  array<string, list<string>>  $errors  column key => messages
     */
    public static function withErrors(array $errors): self
    {
        return new self(422, 'validation_failed', 'بعض البيانات غير صحيحة', $errors);
    }
}
