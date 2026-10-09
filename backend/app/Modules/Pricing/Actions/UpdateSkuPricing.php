<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Services\SkuPricePlanner;
use Illuminate\Support\Facades\DB;

/**
 * Sets cover price, base price, MOQ and replaces the tiers of one SKU atomically (BR-PR01..05, BR-PR10,
 * BR-MOQ01). Each kind of change is its own audit record so the history reads as such (BR-L):
 * `sku.price_changed` (cover/base price and its %), `sku.tiers_changed`, `sku.moq_changed`; nothing is
 * audited when nothing changed. Bulk pricing passes its batch id.
 *
 * A SKU that is not saved yet (product create, duplicate) is saved with its prices and tiers and not
 * audited here: its creation is audited by the Catalog action that creates it.
 *
 * Opens a transaction, which nests into the caller's when there is one (CreateProduct, bulk pricing).
 */
final readonly class UpdateSkuPricing
{
    public function __construct(
        private SkuPricePlanner $planner,
        private AuditLogger $audit,
    ) {}

    public function handle(Sku $sku, SkuPricingData $data, ?string $batchId = null): Sku
    {
        $before = $sku->exists ? $this->planner->current($sku) : null;
        $after = $this->planner->plan($sku, $data);

        return DB::transaction(function () use ($sku, $before, $after, $batchId): Sku {
            $sku->fill([
                'cover_price' => $after['cover_price'],
                'base_price' => $after['base_price'],
                'base_price_percent' => $after['base_price_percent'],
                'moq' => $after['moq'],
            ])->save();

            if ($before === null || $before['tiers'] !== $after['tiers']) {
                $sku->tiers()->delete();
                $sku->tiers()->createMany($after['tiers']);
            }

            $sku->load('tiers');

            if ($before !== null) {
                $this->auditChanges($sku, $before, $after, $batchId);
            }

            return $sku;
        });
    }

    /**
     * @param  array{cover_price: string|null, base_price: string, base_price_percent: string|null, moq: int, tiers: list<array<string, mixed>>}  $before
     * @param  array{cover_price: string|null, base_price: string, base_price_percent: string|null, moq: int, tiers: list<array<string, mixed>>}  $after
     */
    private function auditChanges(Sku $sku, array $before, array $after, ?string $batchId): void
    {
        $prices = ['cover_price', 'base_price', 'base_price_percent'];
        $oldPrices = array_intersect_key($before, array_flip($prices));
        $newPrices = array_intersect_key($after, array_flip($prices));

        if ($oldPrices !== $newPrices) {
            $this->audit->log('sku.price_changed', $sku, $oldPrices, $newPrices, $batchId);
        }

        if ($before['tiers'] !== $after['tiers']) {
            $this->audit->log('sku.tiers_changed', $sku, ['tiers' => $before['tiers']], ['tiers' => $after['tiers']], $batchId);
        }

        if ($before['moq'] !== $after['moq']) {
            $this->audit->log('sku.moq_changed', $sku, ['moq' => $before['moq']], ['moq' => $after['moq']], $batchId);
        }
    }
}
