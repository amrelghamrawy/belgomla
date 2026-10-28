<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Support\ApiException;

/**
 * V2 Task 06: a SKU of a catalog file failed CatalogRowMapper's checks (a reference it cannot resolve, a value
 * the product request rules refuse). Each problem names the file row (a SKU has one per unit since V2 Task 13) and the
 * column key (`base_price`, `field:grade`, `tier_2_min`), so the preview can point at the cell. Never reaches the API
 * as such: the import lists it per row.
 */
final class InvalidImportRow extends ApiException
{
    /**
     * V2 Task 13: the problems of a SKU's rows (one per unit, D91), each in `items` as `{ row, column, message }`.
     *
     * @param  array<int, array<string, list<string>>>  $errors  row number => column key => messages
     */
    public static function inRows(array $errors): self
    {
        $items = [];

        foreach ($errors as $row => $columns) {
            foreach ($columns as $column => $messages) {
                foreach ($messages as $message) {
                    $items[] = ['row' => $row, 'column' => $column, 'message' => $message];
                }
            }
        }

        return new self(422, 'validation_failed', 'بعض البيانات غير صحيحة', null, $items);
    }
}
