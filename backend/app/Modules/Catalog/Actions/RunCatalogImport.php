<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Exceptions\ImportUnavailable;
use App\Modules\Catalog\Jobs\ImportCatalog;
use App\Modules\Catalog\Models\CatalogImport;
use App\Modules\Catalog\Services\CatalogFiles;
use Illuminate\Support\Facades\DB;

/**
 * Starts the import of a previewed file (V2 Task 06): creates the `imports` row with a fresh audit batch id, audits
 * `catalog.import_started` under that batch as the user who asked (the job's own records are by `system`, D95) and
 * queues ImportCatalog once the row is committed. A token is imported once: asking again returns the same run. A
 * token whose file is gone or older than `catalog.import_token_hours` is `422 import_expired`. Owns its transaction.
 */
final readonly class RunCatalogImport
{
    public function __construct(
        private CatalogFiles $files,
        private AuditLogger $audit,
    ) {}

    public function handle(string $token, ?int $actorId = null): CatalogImport
    {
        $existing = CatalogImport::query()->where('uuid', $token)->first();

        if ($existing instanceof CatalogImport) {
            return $existing;
        }

        $file = $this->files->upload($token) ?? throw ImportUnavailable::expired();
        $batchId = $this->audit->newBatchId();

        return DB::transaction(function () use ($token, $file, $batchId, $actorId): CatalogImport {
            $import = CatalogImport::query()->create([
                'uuid' => $token,
                'file_name' => $file['name'],
                'batch_id' => $batchId,
                'created_by' => $actorId,
            ]);

            $this->audit->log('catalog.import_started', $import, null, ['file_name' => $file['name']], $batchId);

            ImportCatalog::dispatch($import->id)->afterCommit();

            return $import->refresh();
        });
    }
}
