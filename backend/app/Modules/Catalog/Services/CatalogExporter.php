<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Exceptions\ImportUnavailable;
use App\Modules\Catalog\Jobs\ExportCatalog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Queries\CatalogExportQuery;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * The catalog export (V2 Task 06): the dashboard filter's products in the import format (CatalogRowMapper), so the
 * file comes back through the import unchanged. Up to `catalog.export_sync_limit` products stream straight to the
 * response; above it the ExportCatalog job writes the file and the dashboard downloads it from a temporary signed
 * link (D95). The import template is the same file without rows.
 */
final readonly class CatalogExporter
{
    public function __construct(
        private Spreadsheet $spreadsheet,
        private CatalogRowMapper $mapper,
        private CatalogExportQuery $query,
        private CatalogFiles $files,
        private TenantContext $tenant,
    ) {}

    /** Null when the selection is small enough to stream now; otherwise the id of the export queued for it. */
    public function queueIfLarge(ProductFilterData $filter, SpreadsheetFormat $format): ?string
    {
        if ($this->query->count($filter) <= config()->integer('catalog.export_sync_limit')) {
            return null;
        }

        $uuid = (string) Str::uuid();
        ExportCatalog::dispatch($this->tenant->get()->subdomain, $uuid, $filter, $format);

        return $uuid;
    }

    public function write(string $path, ProductFilterData $filter, SpreadsheetFormat $format): void
    {
        $lookups = $this->mapper->lookups();

        $this->spreadsheet->write(
            $path,
            $format,
            $this->mapper->columns($lookups),
            $this->query->get($filter)->flatMap(fn (Product $product): array => $this->mapper->exportRows($product, $lookups)),
            CatalogRowMapper::SHEET_NAME,
            withKeys: true,
        );
    }

    public function template(string $path, SpreadsheetFormat $format): void
    {
        $this->spreadsheet->write($path, $format, $this->mapper->columns($this->mapper->lookups()), [], CatalogRowMapper::SHEET_NAME, withKeys: true);
    }

    /** Writes a queued export to the tenant's export folder (the ExportCatalog job). */
    public function store(string $uuid, ProductFilterData $filter, SpreadsheetFormat $format): void
    {
        $this->write($this->files->newExport($uuid, $format), $filter, $format);
        $this->files->finishExport($uuid, $format);
    }

    /**
     * The file of a queued export, for a request whose link signature is valid.
     *
     * @return array{path: string, format: SpreadsheetFormat}
     */
    public function download(string $uuid, bool $validSignature): array
    {
        $export = $validSignature ? $this->files->export($uuid) : 'failed';

        return match ($export) {
            'pending' => throw ImportUnavailable::exportNotReady(),
            'failed' => throw ImportUnavailable::exportMissing(),
            default => $export,
        };
    }

    public function fileName(string $name, SpreadsheetFormat $format): string
    {
        return "{$name}-".now()->format('Y-m-d').".{$format->value}";
    }
}
