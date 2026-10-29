<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Support\ApiException;

/** BR-P15 (V2 Task 15, D93): what cannot be done with or to a bundle. */
final class BundleRefused extends ApiException
{
    /** A bundle is edited through its own endpoint (its SKU, components and pricing belong together). */
    public static function isBundle(): self
    {
        return new self(409, 'is_bundle', 'هذا المنتج باقة: عدّله من شاشة الباقة');
    }

    /** The bundle endpoints only address bundles. */
    public static function notABundle(): self
    {
        return new self(404, 'not_found', 'الباقة غير موجودة');
    }

    public static function emptyFilter(): self
    {
        return new self(422, 'validation_failed', 'بعض البيانات غير صحيحة', ['filter' => ['اختر صفًا أو شركة أو قسمًا على الأقل']]);
    }

    public static function nothingSelected(): self
    {
        return new self(422, 'validation_failed', 'بعض البيانات غير صحيحة', ['filter' => ['لا توجد منتجات نشطة مطابقة لهذا الاختيار']]);
    }

    public static function tooManySelected(int $max): self
    {
        return new self(422, 'validation_failed', 'بعض البيانات غير صحيحة', ['filter' => ["الاختيار يضم أكثر من {$max} منتجًا؛ ضيّق الاختيار"]]);
    }
}
