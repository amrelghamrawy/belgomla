<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Data\SkuUnitData;
use App\Modules\Catalog\Enums\ArchiveStatus;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Pricing\Actions\UpdateSkuPricing;
use App\Support\ApiException;
use Illuminate\Database\Eloquent\Collection;

/**
 * V2 Task 13 (BR-U02, D91): makes the units a SKU is sold in what the product form sent, in that order. A unit sent with
 * its `id`, or with the `unit_id` of a unit the SKU had (even archived), is updated and active again; any other is created.
 * A unit left out is archived, never deleted: order lines and carts point at it, and sending it again restores it with
 * its prices. Exactly one unit is the default afterwards (the defaults are cleared first, so the one-default index never
 * sees two). Each unit's prices, MOQ and tiers go through Pricing's UpdateSkuPricing, which audits a change to an
 * existing unit; new and archived units show in the product's own audit record. The ids are checked beforehand by
 * ProductReferences. Joins the caller's transaction.
 */
final readonly class SaveSkuUnits
{
    public function __construct(private UpdateSkuPricing $pricing) {}

    /**
     * @param  non-empty-list<SkuUnitData>  $units
     */
    public function handle(Sku $sku, array $units, ?string $batchId = null, string $path = 'sku'): void
    {
        SkuUnit::query()->where('sku_id', $sku->id)->where('is_default', true)->update(['is_default' => false]);

        $existing = SkuUnit::query()->where('sku_id', $sku->id)->get();
        $named = array_filter(array_map(fn (SkuUnitData $data): ?int => $data->id, $units));
        $kept = [];

        foreach ($units as $index => $data) {
            $unit = $this->match($existing, $data, $named) ?? $sku->units()->make();
            $unit->fill($data->attributes($index));

            $kept[] = $this->priced($unit, $data, "{$path}.units.{$index}.", $batchId)->id;
        }

        SkuUnit::query()
            ->where('sku_id', $sku->id)
            ->whereKeyNot($kept)
            ->where('status', ArchiveStatus::Active)
            ->update(['status' => ArchiveStatus::Archived]);
    }

    /**
     * A pricing refusal names the unit it is about: its keys move under `{path}.units.{index}.` (`path` = "sku" or "skus.{i}", V2 Task 14) (a refusal without
     * details, `cover_price_required`, gets the unit's `cover_price`), so the form and the import point at the right unit.
     */
    private function priced(SkuUnit $unit, SkuUnitData $data, string $prefix, ?string $batchId): SkuUnit
    {
        try {
            return $this->pricing->handle($unit, $data->pricing, $batchId);
        } catch (ApiException $e) {
            $details = [];

            foreach ($e->details ?? ['cover_price' => [$e->getMessage()]] as $key => $messages) {
                $details["{$prefix}{$key}"] = $messages;
            }

            throw new ApiException($e->status, $e->errorCode, $e->getMessage(), $details, $e->items);
        }
    }

    /**
     * By id; else the unit the SKU had in the same `unit_id`, unless another line of the form names that one by id.
     *
     * @param  Collection<int, SkuUnit>  $existing
     * @param  array<int, int>  $named  ids the form sends
     */
    private function match(Collection $existing, SkuUnitData $data, array $named): ?SkuUnit
    {
        return $data->id !== null
            ? $existing->firstWhere('id', $data->id)
            : $existing->first(fn (SkuUnit $unit): bool => $unit->unit_id === $data->unitId && ! in_array($unit->id, $named, true));
    }
}
