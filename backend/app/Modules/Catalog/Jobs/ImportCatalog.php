<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Actions\FailCatalogImport;
use App\Modules\Catalog\Actions\ProcessCatalogImport;
use App\Modules\Catalog\Models\CatalogImport;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Applies a previewed catalog file (V2 Task 06) on the `default` queue, inside the import's own tenant, one chunk of
 * rows per job (`catalog.import_chunk`) so no job outlives the queue's retry window; while rows remain it queues
 * itself again. Tried once: rows already applied must not be applied twice by a retry, so a worker that dies leaves
 * the run `failed` (its applied rows stay, under the audit batch) and the merchant imports the rest again.
 */
final class ImportCatalog implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('default');
    }

    public function handle(TenantContext $context, ProcessCatalogImport $process): void
    {
        $more = $this->inTenant($context, $process->handle(...));

        if ($more) {
            self::dispatch($this->importId);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->inTenant(app(TenantContext::class), function (CatalogImport $import): bool {
            app(FailCatalogImport::class)->handle($import, 'توقف الاستيراد بسبب خطأ غير متوقع، راجع النتيجة ثم ارفع الباقي مرة أخرى');

            return false;
        });
    }

    /**
     * @param  callable(CatalogImport): bool  $callback
     */
    private function inTenant(TenantContext $context, callable $callback): bool
    {
        // Queued work starts without a tenant: the import row tells which one (rules/00 "Never", platform code).
        $import = CatalogImport::withoutTenantScope()->with('tenant')->find($this->importId);

        if (! $import instanceof CatalogImport || $import->tenant === null) {
            return false;
        }

        return $context->runAs($import->tenant, fn (): bool => $callback($import));
    }
}
