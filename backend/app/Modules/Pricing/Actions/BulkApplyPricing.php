<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Data\BulkPricingData;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Exceptions\InvalidPricingInput;
use App\Modules\Pricing\Queries\BulkPricingTargetsQuery;
use App\Modules\Pricing\Services\SkuPricePlanner;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BR-PR11 bulk pricing by filter: every active unit of the SKU of the matching products (or only the units sold in the
 * given store unit, V2 Task 13, D91) gets base price = X% off its own cover price and the given % tiers (replacing its
 * tiers), plus the MOQ when one is given. Units without a cover price are skipped and reported.
 *
 * Preview (`$apply = false`) plans every SKU, so invalid tiers fail the same way, and writes nothing.
 * Apply writes everything in one transaction (owned here) under one audit batch: the per-SKU records of
 * UpdateSkuPricing plus one `pricing.bulk_applied` record. An empty filter is refused.
 *
 * @phpstan-type Skipped array{product_id: int, sku_id: int, sku_unit_id: int, code: string, name: string, unit: string}
 */
final readonly class BulkApplyPricing
{
    public function __construct(
        private BulkPricingTargetsQuery $targets,
        private SkuPricePlanner $planner,
        private UpdateSkuPricing $update,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{affected: int, skipped_no_cover_price: list<Skipped>, batch_id: string|null}
     */
    public function handle(BulkPricingData $data, bool $apply = true): array
    {
        if ($data->filter->isEmpty()) {
            throw InvalidPricingInput::emptyFilter();
        }

        if (! $apply) {
            return $this->preview($data);
        }

        $batchId = $this->audit->newBatchId();

        return DB::transaction(function () use ($data, $batchId): array {
            [$priced, $skipped] = $this->split($this->targets->get($data->filter, $data->unitId));

            foreach ($priced as $unit) {
                $this->update->handle($unit, $this->pricingFor($unit, $data), $batchId);
            }

            $this->audit->log('pricing.bulk_applied', 'skus', null, [
                'base_price_percent' => $data->basePricePercent,
                'tiers' => array_map(fn (TierData $tier): array => ['min_quantity' => $tier->minQuantity, 'discount_percent' => $tier->discountPercent], $data->tiers),
                'moq' => $data->moq,
                'unit_id' => $data->unitId,
                'affected' => $priced->count(),
                'skipped_no_cover_price' => count($skipped),
            ], $batchId);

            return ['affected' => $priced->count(), 'skipped_no_cover_price' => $skipped, 'batch_id' => $batchId];
        });
    }

    /**
     * @return array{affected: int, skipped_no_cover_price: list<Skipped>, batch_id: null}
     */
    private function preview(BulkPricingData $data): array
    {
        [$priced, $skipped] = $this->split($this->targets->get($data->filter, $data->unitId));

        foreach ($priced as $unit) {
            $this->planner->plan($unit, $this->pricingFor($unit, $data));
        }

        return ['affected' => $priced->count(), 'skipped_no_cover_price' => $skipped, 'batch_id' => null];
    }

    /**
     * @param  Collection<int, SkuUnit>  $units
     * @return array{0: Collection<int, SkuUnit>, 1: list<Skipped>}
     */
    private function split(Collection $units): array
    {
        $priced = new Collection;
        $skipped = [];

        foreach ($units as $unit) {
            if ($unit->cover_price !== null) {
                $priced->push($unit);

                continue;
            }

            $sku = $unit->sku;
            $skipped[] = ['product_id' => $sku->product_id, 'sku_id' => $sku->id, 'sku_unit_id' => $unit->id, 'code' => $sku->code, 'name' => $sku->product->name, 'unit' => $unit->unit->name];
        }

        return [$priced, $skipped];
    }

    private function pricingFor(SkuUnit $unit, BulkPricingData $data): SkuPricingData
    {
        return new SkuPricingData(
            basePrice: null,
            basePricePercent: $data->basePricePercent,
            moq: $data->moq ?? $unit->moq,
            tiers: $data->tiers,
            coverPriceGiven: false,
        );
    }
}
