<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Support\ApiException;

/**
 * V2 Task 06: an uploaded catalog file that cannot be imported as a whole (unreadable, another format version, no
 * key row, required columns missing, no rows). Rendered like a validation failure on `file`; row problems are not
 * this exception, they are listed per row by the preview.
 */
final class InvalidImportFile extends ApiException
{
    public static function because(string $message): self
    {
        return new self(422, 'validation_failed', 'بعض البيانات غير صحيحة', ['file' => [$message]]);
    }

    public static function unreadable(): self
    {
        return self::because('تعذّرت قراءة الملف، ارفع ملف Excel (xlsx) أو CSV');
    }

    public static function unsupportedVersion(string $sheetName): self
    {
        return self::because("إصدار الملف «{$sheetName}» غير مدعوم، نزّل قالبًا جديدًا");
    }

    /**
     * @param  list<string>  $columns
     */
    public static function missingColumns(array $columns): self
    {
        return self::because('أعمدة ناقصة في الملف: '.implode('، ', $columns));
    }

    public static function noHeader(): self
    {
        return self::because('لم يتم التعرف على صف العناوين، استخدم القالب');
    }

    public static function empty(): self
    {
        return self::because('الملف لا يحتوي على منتجات');
    }
}
