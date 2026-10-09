<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Data\BulkStatusData;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Exceptions\InvalidCatalogInput;
use App\Modules\Catalog\Queries\FilteredProductsQuery;
use Illuminate\Support\Facades\DB;

/**
 * BR-P12 season rollover: one status for every product matching the filter (e.g. inactivate
 * academic_year = 2025/2026). One audit record per changed product plus one for the bulk action, all
 * sharing one batch id (BR-L). Products whose transition is not allowed are skipped and counted (D29).
 * An empty filter is refused so a mistake cannot touch the whole catalog. Owns its transaction.
 */
final readonly class BulkSetProductStatus
{
    public function __construct(
        private FilteredProductsQuery $products,
        private SetProductStatus $setStatus,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{affected: int, skipped: int, batch_id: string}
     */
    public function handle(BulkStatusData $data): array
    {
        if ($data->filter->isEmpty()) {
            throw InvalidCatalogInput::field('filter', 'حدد شرطًا واحدًا على الأقل');
        }

        $batchId = $this->audit->newBatchId();

        return DB::transaction(function () use ($data, $batchId): array {
            $affected = 0;
            $skipped = 0;

            foreach ($this->products->builder($data->filter)->orderBy('products.id')->get() as $product) {
                if (! $product->status->canTransitionTo($data->status)) {
                    $skipped++;

                    continue;
                }

                $this->setStatus->handle($product, $data->status, $batchId);
                $affected++;
            }

            $this->audit->log('product.bulk_status_changed', 'products', null, [
                'status' => $data->status->value,
                'filter' => $this->describe($data->filter),
                'affected' => $affected,
                'skipped' => $skipped,
            ], $batchId);

            return ['affected' => $affected, 'skipped' => $skipped, 'batch_id' => $batchId];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(ProductFilterData $filter): array
    {
        return array_filter([
            'category' => $filter->categoryId ?? $filter->categorySlug,
            'f' => $filter->fields,
            'q' => $filter->q,
            'status' => $filter->status?->value,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
