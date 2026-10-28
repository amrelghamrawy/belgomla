<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Support\ApiException;

/** A business key that must be unique within the tenant is already taken (409). */
final class CatalogConflict extends ApiException
{
    public static function duplicateUnitName(): self
    {
        return new self(409, 'duplicate_name', 'توجد وحدة بنفس الاسم');
    }

    public static function duplicateFieldKey(): self
    {
        return new self(409, 'duplicate_key', 'يوجد حقل بنفس المفتاح');
    }

    public static function duplicateOptionLabel(): self
    {
        return new self(409, 'duplicate_label', 'يوجد اختيار بنفس الاسم في هذا الحقل');
    }

    /** `$field` names the variant whose code is taken (V2 Task 14, "skus.{i}.code"). */
    public static function duplicateSkuCode(?string $field = null): self
    {
        return new self(409, 'duplicate_code', 'كود الصنف مستخدم بالفعل', $field === null ? null : [$field => ['كود الصنف مستخدم بالفعل']]);
    }

    public static function duplicateRelation(): self
    {
        return new self(409, 'duplicate_relation', 'هذا الربط موجود بالفعل');
    }
}
