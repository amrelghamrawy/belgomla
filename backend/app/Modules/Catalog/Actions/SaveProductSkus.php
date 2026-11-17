<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\ProductOptionData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Enums\SkuStatus;
use App\Modules\Catalog\Exceptions\BaseUnitLocked;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Queries\SkuHistoryQuery;
use App\Modules\Catalog\Services\VariantGenerator;
use App\Modules\Inventory\Actions\RecordOpeningStock;
use App\Modules\Inventory\Queries\StockHeldQuery;
use App\Support\ArabicText;
use Illuminate\Support\Facades\DB;

/**
 * V2 Task 14 (BR-P02, BR-P14, D92): makes a product's options and SKUs what the product form sent.
 *
 *  - Options and values are matched by id, else by name / value (folded, D32); the rest of the product's options and
 *    values are deleted, with the SKUs' links to them (a SKU keeps its label in `skus.name`).
 *  - Each SKU sent updates the product's SKU it stands for (ProductReferences::matches) or is created with its opening
 *    stock (Inventory's RecordOpeningStock); its `sort_order` is its place in the form, its `name` the variant label
 *    («أزرق / M», null without options), its option values are linked, and its units go through SaveSkuUnits.
 *  - A SKU of the product not sent is deleted when it has no history (SkuHistoryQuery), otherwise set inactive and kept
 *    (BR-P05) after the SKUs sent: order lines, stock movements, negotiated prices and promotions point at it.
 *
 * Joins the caller's transaction (CreateProduct, UpdateProduct).
 */
final readonly class SaveProductSkus
{
    public function __construct(
        private SaveSkuUnits $units,
        private RecordOpeningStock $openingStock,
        private SkuHistoryQuery $history,
        private VariantGenerator $variants,
        private StockHeldQuery $stock,
    ) {}

    /**
     * @param  array<int, Sku|null>  $matches  ProductReferences::matches(), by index of `$data->skus`
     */
    public function handle(Product $product, ProductData $data, array $matches, ?int $actorId = null, ?string $batchId = null): void
    {
        $values = $this->options($product, $data->options);
        $kept = [];

        foreach ($data->skus as $index => $skuData) {
            $kept[] = $this->sku($product, $skuData, $matches[$index] ?? null, $index, $values, $actorId, $batchId)->id;
        }

        $this->removeOthers($product, $kept);
    }

    /**
     * Syncs the options; returns, per option index, folded value => value row.
     *
     * @param  list<ProductOptionData>  $options
     * @return list<array<string, ProductOptionValue>>
     */
    private function options(Product $product, array $options): array
    {
        $existing = ProductOption::query()->with('values')->where('product_id', $product->id)->get();
        $rows = [];
        $taken = [];

        foreach ($options as $data) {
            $option = $data->id !== null
                ? $existing->firstWhere('id', $data->id)
                : $existing->first(fn (ProductOption $option): bool => ! in_array($option->id, $taken, true) && ArabicText::normalize($option->name) === ArabicText::normalize($data->name));
            $taken[] = $option?->id;
            $rows[] = $option;
        }

        ProductOption::query()->where('product_id', $product->id)->whereKeyNot(array_values(array_filter($taken)))->delete();

        $lookups = [];

        foreach ($options as $index => $data) {
            $option = $rows[$index] ?? new ProductOption(['product_id' => $product->id]);
            $option->fill(['name' => $data->name, 'sort_order' => $index])->save();
            $lookups[] = $this->values($option, $data);
        }

        return $lookups;
    }

    /**
     * @return array<string, ProductOptionValue>
     */
    private function values(ProductOption $option, ProductOptionData $data): array
    {
        $existing = $option->exists ? ProductOptionValue::query()->where('product_option_id', $option->id)->get() : null;
        $rows = [];
        $taken = [];

        foreach ($data->values as $value) {
            $row = $value->id !== null
                ? $existing?->firstWhere('id', $value->id)
                : $existing?->first(fn (ProductOptionValue $row): bool => ! in_array($row->id, $taken, true) && ArabicText::normalize($row->value) === ArabicText::normalize($value->value));
            $taken[] = $row?->id;
            $rows[] = $row;
        }

        ProductOptionValue::query()->where('product_option_id', $option->id)->whereKeyNot(array_values(array_filter($taken)))->delete();

        $lookup = [];

        foreach ($data->values as $index => $value) {
            $row = $rows[$index] ?? new ProductOptionValue(['product_option_id' => $option->id]);
            $row->fill(['value' => $value->value, 'sort_order' => $index, 'image_id' => $value->imageId])->save();
            $lookup[ArabicText::normalize($value->value)] = $row;
        }

        return $lookup;
    }

    /**
     * @param  list<array<string, ProductOptionValue>>  $values
     */
    private function sku(Product $product, SkuData $data, ?Sku $sku, int $index, array $values, ?int $actorId, ?string $batchId): Sku
    {
        $created = ! $sku instanceof Sku;
        $sku ??= $product->skus()->make();

        if (! $created && $sku->base_unit_id !== $data->baseUnitId && $this->stock->holds($sku->id)) {
            throw BaseUnitLocked::at($data->path); // D99 c
        }

        $sku->fill([...$data->attributes($index), 'name' => $this->variants->label($data->optionValues)])->save();

        $this->link($sku, $data, $values);
        $this->units->handle($sku, $data->units, $batchId, $data->path);

        if ($created) {
            $this->openingStock->handle($sku, $data->openingStock, $actorId);
        }

        return $sku;
    }

    /**
     * @param  list<array<string, ProductOptionValue>>  $values
     */
    private function link(Sku $sku, SkuData $data, array $values): void
    {
        DB::table('sku_option_values')->where('sku_id', $sku->id)->delete();

        foreach ($data->optionValues as $index => $text) {
            $value = $values[$index][ArabicText::normalize($text)] ?? null;

            if ($value instanceof ProductOptionValue) {
                DB::table('sku_option_values')->insert([
                    'tenant_id' => $sku->tenant_id,
                    'sku_id' => $sku->id,
                    'product_option_id' => $value->product_option_id,
                    'product_option_value_id' => $value->id,
                ]);
            }
        }
    }

    /**
     * @param  list<int>  $kept
     */
    private function removeOthers(Product $product, array $kept): void
    {
        $removed = Sku::query()->where('product_id', $product->id)->whereKeyNot($kept)->get()->map(fn (Sku $sku): int => $sku->id)->values()->all();
        $history = $this->history->withHistory(array_values($removed));

        Sku::query()->whereKey($history)->update(['status' => SkuStatus::Inactive, 'sort_order' => count($kept)]); // after the ones sent
        Sku::query()->whereKey(array_values(array_diff($removed, $history)))->delete();
    }
}
