<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Enums\ImportStatus;
use App\Modules\Catalog\Models\CatalogImport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A queued import run without a file on disk; tests that run it store the file through PreviewCatalogImport.
 *
 * @extends Factory<CatalogImport>
 */
final class CatalogImportFactory extends Factory
{
    protected $model = CatalogImport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fn (): string => (string) Str::uuid(),
            'file_name' => 'products.xlsx',
            'status' => ImportStatus::Queued,
            'batch_id' => fn (): string => (string) Str::uuid(),
            'created_by' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(['status' => ImportStatus::Running, 'started_at' => now()]);
    }

    public function done(): static
    {
        return $this->state(['status' => ImportStatus::Done, 'started_at' => now(), 'finished_at' => now()]);
    }

    public function failed(): static
    {
        return $this->state(['status' => ImportStatus::Failed, 'started_at' => now(), 'finished_at' => now()]);
    }
}
