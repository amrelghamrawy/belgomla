<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Support\ApiException;

/**
 * V2 Task 06: a file the import or export no longer (or not yet) has. A preview token is good for
 * `catalog.import_token_hours` (`422 import_expired`); a queued export answers `409 export_not_ready` until its job
 * has written the file, and `404 not_found` when the link is wrong, expired or the export failed.
 */
final class ImportUnavailable extends ApiException
{
    public static function expired(): self
    {
        return new self(422, 'import_expired', 'انتهت صلاحية الملف المرفوع، ارفعه مرة أخرى');
    }

    public static function exportNotReady(): self
    {
        return new self(409, 'export_not_ready', 'الملف قيد التجهيز، حاول بعد قليل');
    }

    public static function exportMissing(): self
    {
        return new self(404, 'not_found', 'رابط التحميل غير صالح أو انتهت صلاحيته، صدّر مرة أخرى');
    }
}
