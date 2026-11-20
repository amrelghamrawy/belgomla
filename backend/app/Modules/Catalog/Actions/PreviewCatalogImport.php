<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Data\ImportPreview;
use App\Modules\Catalog\Enums\ImportRowAction;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Exceptions\InvalidImportFile;
use App\Modules\Catalog\Services\CatalogFiles;
use App\Modules\Catalog\Services\CatalogRowMapper;
use App\Modules\Catalog\Services\Spreadsheet;
use App\Support\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The import preview (V2 Task 06): stores the uploaded file for `catalog.import_token_hours` and dry-runs every
 * product (its rows, CatalogRowMapper::products; the counts are products, V2 Task 14) through ImportCatalogRow inside
 * one transaction that is always rolled back, so the preview finds exactly
 * the errors the import would (references, request rules, D28 tiers, custom fields, stock below reserved) and
 * writes nothing. Returns the counts, the first `catalog.import_preview_errors` row errors and the token that
 * RunCatalogImport takes. A file that cannot be read as a whole is `422` on `file` and is not kept.
 */
final readonly class PreviewCatalogImport
{
    public function __construct(
        private CatalogFiles $files,
        private Spreadsheet $spreadsheet,
        private CatalogRowMapper $mapper,
        private ImportCatalogRow $importRow,
    ) {}

    public function handle(UploadedFile $upload): ImportPreview
    {
        $file = $this->files->storeUpload($upload);

        try {
            return $this->dryRun($file['token'], $file['name'], $file['path'], $file['format']);
        } catch (ApiException $e) {
            $this->files->deleteUpload($file['token']);

            throw $e;
        }
    }

    private function dryRun(string $token, string $name, string $path, SpreadsheetFormat $format): ImportPreview
    {
        $lookups = $this->mapper->lookups();
        $products = $this->mapper->products($this->mapper->rows($this->spreadsheet->read($path, $format), $lookups, $this->spreadsheet->sheetName($path, $format)));
        $counts = array_fill_keys(array_map(fn (ImportRowAction $action): string => $action->value, ImportRowAction::cases()), 0);
        $limit = config()->integer('catalog.import_preview_errors');
        $errors = [];
        $errorCount = 0;

        DB::beginTransaction();

        try {
            foreach ($products as $product) {
                $result = $this->importRow->handle($product['rows'], $lookups, $product['duplicates']);
                $counts[$result->action->value]++;

                foreach ($result->rowErrors() as $error) {
                    $errorCount++;

                    if ($errorCount <= $limit) {
                        $errors[] = $error;
                    }
                }
            }
        } finally {
            DB::rollBack();
        }

        if (array_sum($counts) === 0) {
            throw InvalidImportFile::empty();
        }

        return new ImportPreview(
            token: $token,
            fileName: $name,
            total: array_sum($counts),
            create: $counts[ImportRowAction::Create->value],
            update: $counts[ImportRowAction::Update->value],
            skip: $counts[ImportRowAction::Skip->value],
            errors: $errors,
            errorsTruncated: $errorCount > $limit,
        );
    }
}
