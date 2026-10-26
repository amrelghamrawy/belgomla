<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Queries;

use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Queries\FilteredProductsQuery;
use App\Modules\Pricing\Exceptions\InvalidPricingInput;
use App\Modules\Pricing\Exceptions\PromotionRefused;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Pricing\Services\PriceResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * BR-PR15 (V2 Task 12, D90 d): the SKUs a promotion covers: every SKU of the products the shared product filter selects
 * (the bulk pricing selection, BR-PR11; an empty filter is refused like BR-P12), or an explicit list of this store's
 * SKUs. Either must select at least one SKU. Also the filter as a promotion stores it (`promotions.filter`) and back.
 */
final readonly class PromotionTargetsQuery
{
    public function __construct(private FilteredProductsQuery $products, private PriceResolver $prices) {}

    /**
     * @param  list<int>|null  $skuIds
     * @return list<int> the SKU ids, ascending
     *
     * @throws PromotionRefused|InvalidPricingInput
     */
    public function skuIds(?ProductFilterData $filter, ?array $skuIds): array
    {
        if ($filter instanceof ProductFilterData) {
            if ($filter->isEmpty()) {
                throw InvalidPricingInput::emptyFilter();
            }

            $ids = $this->ids($this->selection($filter));

            return $ids === [] ? throw PromotionRefused::nothingSelected('filter') : $ids;
        }

        $wanted = array_values(array_unique($skuIds ?? []));

        if ($wanted === []) {
            throw PromotionRefused::nothingSelected('sku_ids');
        }

        $found = $this->ids(Sku::query()->whereKey($wanted));

        return count($found) === count($wanted) ? $found : throw PromotionRefused::unknownSkus();
    }

    /**
     * D90 d: how many SKUs a promotion would cover and the first few, priced under `$percent` when given. Writes nothing.
     *
     * @param  list<int>|null  $skuIds
     * @return array{skus_count: int, sample: list<array{sku_id: int, code: string, product_name: string, base_price: string, promotion_price: string|null}>}
     */
    public function preview(?ProductFilterData $filter, ?array $skuIds, ?string $percent, int $limit): array
    {
        $ids = $this->skuIds($filter, $skuIds);
        $sample = Sku::query()->whereKey(array_slice($ids, 0, $limit))->with('product')->orderBy('id')->get();

        return [
            'skus_count' => count($ids),
            'sample' => array_values($sample->map(fn (Sku $sku): array => [
                'sku_id' => $sku->id,
                'code' => $sku->code,
                'product_name' => $sku->product->name,
                'base_price' => $sku->base_price,
                'promotion_price' => $percent === null ? null : $this->prices->promotionPrice($sku, $percent),
            ])->all()),
        ];
    }

    /**
     * The filter as `promotions.filter` keeps it (the dashboard's shape: category id, `f`, `q`, status).
     *
     * @return array{category: int|null, f: array<string, string>, q: string|null, status: string|null}
     */
    public function stored(ProductFilterData $filter): array
    {
        return ['category' => $filter->categoryId, 'f' => $filter->fields, 'q' => $filter->q, 'status' => $filter->status?->value];
    }

    /** The stored filter of a promotion, null for an explicit SKU list. */
    public function filterOf(Promotion $promotion): ?ProductFilterData
    {
        $stored = $promotion->filter;

        if ($stored === null) {
            return null;
        }

        $fields = [];

        foreach ((array) ($stored['f'] ?? []) as $key => $value) {
            $fields[(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        return new ProductFilterData(
            categoryId: is_int($stored['category'] ?? null) ? $stored['category'] : null,
            fields: $fields,
            q: is_string($stored['q'] ?? null) ? $stored['q'] : null,
            status: is_string($stored['status'] ?? null) ? ProductStatus::tryFrom($stored['status']) : null,
        );
    }

    /**
     * The SKUs a promotion covers now, ascending (UpdatePromotion compares them with the new selection).
     *
     * @return list<int>
     */
    public function current(Promotion $promotion): array
    {
        return $this->ids(Sku::query()->whereIn('skus.id', $promotion->skus()->select('skus.id')));
    }

    /**
     * @param  Builder<Sku>  $skus
     * @return list<int>
     */
    private function ids(Builder $skus): array
    {
        return array_values($skus->orderBy('skus.id')->get(['skus.id'])->map(fn (Sku $sku): int => $sku->id)->all());
    }

    /**
     * @return Builder<Sku>
     */
    private function selection(ProductFilterData $filter): Builder
    {
        return Sku::query()->whereIn('skus.product_id', $this->products->builder($filter)->select('products.id'));
    }
}
