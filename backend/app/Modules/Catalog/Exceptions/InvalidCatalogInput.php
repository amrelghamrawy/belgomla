<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Support\ApiException;

/**
 * Input that passed the request rules but is invalid against the tenant's data (an id of another
 * tenant, an archived reference, a field combination). Rendered exactly like a validation failure.
 */
final class InvalidCatalogInput extends ApiException
{
    public static function field(string $field, string $message): self
    {
        return new self(422, 'validation_failed', 'بعض البيانات غير صحيحة', [$field => [$message]]);
    }

    public static function unknownReference(string $field): self
    {
        return self::field($field, 'العنصر المختار غير موجود');
    }

    public static function archivedReference(string $field): self
    {
        return self::field($field, 'العنصر المختار مؤرشف');
    }

    public static function notFilterable(): self
    {
        return self::field('is_filterable', 'التصفية متاحة فقط لحقول الاختيار ونعم/لا');
    }

    public static function optionsNotAllowed(): self
    {
        return self::field('options', 'الاختيارات متاحة فقط لحقول الاختيار من قائمة');
    }

    public static function notASelectField(): self
    {
        return self::field('field', 'هذا الحقل ليس حقل اختيار من قائمة');
    }
}
