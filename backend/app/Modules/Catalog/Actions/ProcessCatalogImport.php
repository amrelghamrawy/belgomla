<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\CatalogLookups;
use App\Modules\Catalog\Data\ImportRowResult;
use App\Modules\Catalog\Enums\ImportRowAction;
use App\Modules\Catalog\Enums\ImportStatus;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Exceptions\InvalidImportFile;
use App\Modules\Catalog\Models\CatalogImport;
use App\Modules\Catalog\Services\CatalogFiles;
use App\Modules\Catalog\Services\CatalogRowMapper;
use App\Modules\Catalog\Services\Spreadsheet;

/**
 * Runs a queued import (V2 Task 06), called by the ImportCatalog job inside the import's tenant, `catalog.import_chunk` rows per
 * call so every job ends well inside the queue's retry window; returns true while rows remain (the job queues the
 * next chunk). The first call counts the rows and marks the run `running`.
 *
 * Products (their rows: one per SKU unit since V2 Task 13, the SKUs of one product since V2 Task 14, CatalogRowMapper::products;
 * `total`, `processed` and the created/updated/skipped counts are products, which for a v1/v2 file are its SKUs) go one by
 * one through ImportCatalogRow, each in its own transaction, so a bad product never undoes the others;
 * every change is audited under the import's batch id. The counts, `processed` and the first
 * `catalog.import_stored_errors` errors are saved after each chunk for the dashboard to poll. At the end the run is
 * `done` with a `catalog.imported` summary record and the uploaded file is deleted; a file that cannot be read any
 * more ends it `failed`. A finished run is left alone.
 */
final readonly class ProcessCatalogImport
{
    public function __construct(
        private CatalogFiles $files,
        private Spreadsheet $spreadsheet,
        private CatalogRowMapper $mapper,
        private ImportCatalogRow $importRow,
        private FailCatalogImport $fail,
        private AuditLogger $audit,
    ) {}

    public function handle(CatalogImport $import): bool
    {
        if ($import->status->isFinished()) {
            return false;
        }

        $file = $this->files->upload($import->uuid, evenExpired: true);

        if ($file === null) {
            $this->fail->handle($import, 'الملف المرفوع لم يعد موجودًا، ارفعه مرة أخرى');

            return false;
        }

        try {
            $more = $this->chunk($import, $this->mapper->lookups(), $file['path'], $file['format']);
        } catch (InvalidImportFile $e) {
            $this->fail->handle($import, $this->fileMessage($e));

            return false;
        }

        if ($more) {
            return true;
        }

        $import->update(['status' => ImportStatus::Done, 'finished_at' => now()]);
        $this->audit->log('catalog.imported', $import, null, $import->only(['total', 'created', 'updated', 'skipped', 'failed']), $import->batch_id);
        $this->files->deleteUpload($import->uuid);

        return false;
    }

    /** Applies the next rows; true when some are left for the next call. */
    private function chunk(CatalogImport $import, CatalogLookups $lookups, string $path, SpreadsheetFormat $format): bool
    {
        $sheetName = $this->spreadsheet->sheetName($path, $format);

        if ($import->status === ImportStatus::Queued) {
            $total = iterator_count($this->mapper->products($this->mapper->rows($this->spreadsheet->read($path, $format), $lookups, $sheetName)));
            $import->update(['status' => ImportStatus::Running, 'started_at' => now(), 'total' => $total]);
        }

        $limit = config()->integer('catalog.import_stored_errors');
        $chunk = config()->integer('catalog.import_chunk');
        $errors = $import->errors;
        $position = 0;
        $applied = 0;
        $more = false;

        foreach ($this->mapper->products($this->mapper->rows($this->spreadsheet->read($path, $format), $lookups, $sheetName)) as $product) {
            if (++$position <= $import->processed) {
                continue;
            }

            if ($applied === $chunk) {
                $more = true;

                break;
            }

            $result = $this->importRow->handle($product['rows'], $lookups, $product['duplicates'], $import->batch_id, $import->created_by);
            $this->count($import, $result);
            $errors = array_slice([...$errors, ...$result->rowErrors()], 0, $limit);
            $applied++;
        }

        $import->errors = $errors;
        $import->save();

        return $more;
    }

    private function count(CatalogImport $import, ImportRowResult $result): void
    {
        $import->processed++;

        match (true) {
            $result->unexpected => $import->failed++,
            $result->action === ImportRowAction::Create => $import->created++,
            $result->action === ImportRowAction::Update => $import->updated++,
            default => $import->skipped++,
        };
    }

    private function fileMessage(InvalidImportFile $e): string
    {
        $messages = $e->details['file'] ?? null;

        return is_array($messages) && is_string($messages[0] ?? null) ? $messages[0] : $e->getMessage();
    }
}
