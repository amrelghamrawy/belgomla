<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Support\ApiException;

/** D26: a product holds at most MAX images. */
final class GalleryFull extends ApiException
{
    public const int MAX = 8;

    public static function make(): self
    {
        return new self(422, 'gallery_full', 'وصلت للحد الأقصى لصور المنتج ('.self::MAX.' صور)', ['max' => self::MAX]);
    }
}
