<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\SpreadsheetFormat;
use App\Modules\Catalog\Services\CatalogExporter;
use App\Modules\Catalog\Services\CatalogFiles;
use App\Modules\Tenancy\Queries\TenantBySubdomainQuery;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Writes a catalog export too large to stream (V2 Task 06, above `catalog.export_sync_limit` products) to the
 * tenant's export folder on the `default` queue; the dashboard polls its signed link until the file is there. A
 * failure leaves a marker so the link answers 404 instead of "not ready" forever.
 */
final class ExportCatalog implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $subdomain,
        public readonly string $uuid,
        public readonly ProductFilterData $filter,
        public readonly SpreadsheetFormat $format,
    ) {
        $this->onQueue('default');
    }

    public function handle(TenantBySubdomainQuery $tenants, TenantContext $context, CatalogExporter $exporter): void
    {
        $this->inTenant($tenants, $context, fn () => $exporter->store($this->uuid, $this->filter, $this->format));
    }

    public function failed(?Throwable $exception): void
    {
        $this->inTenant(app(TenantBySubdomainQuery::class), app(TenantContext::class), fn () => app(CatalogFiles::class)->markExportFailed($this->uuid));
    }

    /**
     * @param  callable(): void  $callback
     */
    private function inTenant(TenantBySubdomainQuery $tenants, TenantContext $context, callable $callback): void
    {
        $tenant = $tenants->find($this->subdomain);

        if (is_object($tenant)) {
            $context->runAs($tenant, fn () => $callback());
        }
    }
}
