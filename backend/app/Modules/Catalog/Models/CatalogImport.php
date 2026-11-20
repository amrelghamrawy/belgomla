<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ImportStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\CatalogImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of the catalog import (V2 Task 06, table `imports`). `uuid` is the preview token of the uploaded file,
 * `batch_id` the audit batch of every change the run makes; `processed` counts the rows read so far (progress).
 * `skipped` rows had errors and were not written, `failed` rows broke unexpectedly; `errors` keeps the first
 * `catalog.import_stored_errors` of them as `{row, column, label, message}` (row 0 = the whole file). `created_by` is a tenant user id.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $uuid
 * @property string $file_name
 * @property ImportStatus $status
 * @property int $total
 * @property int $processed
 * @property int $created
 * @property int $updated
 * @property int $skipped
 * @property int $failed
 * @property list<array{row: int, column: string, label: string, message: string}> $errors
 * @property string $batch_id
 * @property int|null $created_by
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CatalogImport extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CatalogImportFactory> */
    use HasFactory;

    protected $table = 'imports';

    protected $attributes = [
        'status' => 'queued',
        'errors' => '[]',
    ];

    protected static function newFactory(): CatalogImportFactory
    {
        return CatalogImportFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'total' => 'integer',
            'processed' => 'integer',
            'created' => 'integer',
            'updated' => 'integer',
            'skipped' => 'integer',
            'failed' => 'integer',
            'errors' => 'array',
            'created_by' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
