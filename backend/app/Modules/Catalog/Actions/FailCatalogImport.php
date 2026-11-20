<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Enums\ImportStatus;
use App\Modules\Catalog\Models\CatalogImport;

/**
 * Ends an import run as `failed` with one error on the whole file (V2 Task 06): its file is gone or unreadable, or
 * the job died. Rows already applied stay applied (each had its own transaction) and keep their audit records under
 * the batch; `catalog.import_failed` closes the batch. A finished run is left as it is.
 */
final readonly class FailCatalogImport
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(CatalogImport $import, string $message): CatalogImport
    {
        if ($import->status->isFinished()) {
            return $import;
        }

        $import->update([
            'status' => ImportStatus::Failed,
            'finished_at' => now(),
            'errors' => [...$import->errors, ['row' => 0, 'column' => 'file', 'label' => 'الملف', 'message' => $message]],
        ]);

        $this->audit->log('catalog.import_failed', $import, null, ['message' => $message], $import->batch_id);

        return $import;
    }
}
