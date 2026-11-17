<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Support\ApiException;

/**
 * D99 c: the base unit of a SKU holding stock or reservations cannot change: On Hand, Reserved and the average cost
 * are counted in it (BR-U03, BR-INV09), so a new base unit would silently change what every number means.
 * `422 validation_failed` on the form's `base_unit_id`, so the product form and the import point at the field.
 */
final class BaseUnitLocked extends ApiException
{
    public static function at(string $path): self
    {
        return new self(422, 'validation_failed', 'البيانات غير صحيحة', [
            "{$path}.base_unit_id" => ['لا يمكن تغيير الوحدة الأساسية لصنف عليه رصيد أو حجز: صفّر رصيده أولًا'],
        ]);
    }
}
