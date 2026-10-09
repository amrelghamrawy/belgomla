<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Data\BulkPricingData;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Data\TierData;
use App\Modules\Pricing\Exceptions\InvalidPricingInput;
use App\Modules\Pricing\Queries\BulkPricingTargetsQuery;
use App\Modules\Pricing\Services\SkuPricePlanner;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BR-PR11 bulk pricing by filter: every SKU of the matching products gets base price = X% off its own
 * cover price and the given % tiers (replacing its tiers), plus the MOQ when one is given. SKUs without
 * a cover price are skipped and reported.
 *
 * Preview (`$apply = false`) plans every SKU, so invalid tiers fail the same way, and writes nothing.
 * Apply writes everything in one transaction (owned here) under one audit batch: the per-SKU records of
 * UpdateSkuPricing plus one `pricing.bulk_applied` record. An empty filter is refused.
 *
 * @phpstan-type Skipped array{product_id: int, sku_id: int, code: string, name: string}
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
            [$priced, $skipped] = $this->split($this->targets->get($data->filter));

            foreach ($priced as $sku) {
                $this->update->handle($sku, $this->pricingFor($sku, $data), $batchId);
            }

            $this->audit->log('pricing.bulk_applied', 'skus', null, [
                'base_price_percent' => $data->basePricePercent,
                'tiers' => array_map(fn (TierData $tier): array => ['min_quantity' => $tier->minQuantity, 'discount_percent' => $tier->discountPercent], $data->tiers),
                'moq' => $data->moq,
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
        [$priced, $skipped] = $this->split($this->targets->get($data->filter));

        foreach ($priced as $sku) {
            $this->planner->plan($sku, $this->pricingFor($sku, $data));
        }

        return ['affected' => $priced->count(), 'skipped_no_cover_price' => $skipped, 'batch_id' => null];
    }

    /**
     * @param  Collection<int, Sku>  $skus
     * @return array{0: Collection<int, Sku>, 1: list<Skipped>}
     */
    private function split(Collection $skus): array
    {
        $priced = new Collection;
        $skipped = [];

        foreach ($skus as $sku) {
            if ($sku->cover_price !== null) {
                $priced->push($sku);

                continue;
            }

            $skipped[] = ['product_id' => $sku->product_id, 'sku_id' => $sku->id, 'code' => $sku->code, 'name' => $sku->product->name];
        }

        return [$priced, $skipped];
    }

    private function pricingFor(Sku $sku, BulkPricingData $data): SkuPricingData
    {
        return new SkuPricingData(
            basePrice: null,
            basePricePercent: $data->basePricePercent,
            moq: $data->moq ?? $sku->moq,
            tiers: $data->tiers,
            coverPriceGiven: false,
        );
    }
}
