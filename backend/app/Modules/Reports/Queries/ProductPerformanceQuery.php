<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Enums\ProductSort;
use App\Modules\Reports\Enums\ReportName;
use App\Modules\Reports\Services\ReportCache;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * GET /api/dashboard/reports/products (V2 Task 16): per SKU sold or returned in the range (Cairo days, D60), over the
 * delivered orders (by `delivered_at`) and the live returns (by `returned_at`, BR-R05):
 *
 * - `quantity`: selling units that went out on its own lines (a bundle SKU: bundles; units of one SKU may mix);
 * - `quantity_base`: base units that went out, its own lines and inside bundles; `bundle_quantity_base` flags the part
 *   that went out inside bundles (BR-P15, D93: a bundle counts once at its own row and once per component, flagged);
 * - `revenue`: net revenue of its own lines = line totals less the order discount's share (D85) less the returns of
 *   them (a returned component counts against its bundle's row), so the rows' revenues add up to the net sales;
 *   `returns` is that returned amount;
 * - `returned_base` and `return_rate` = returned base units / base units that went out, in % (null when nothing went
 *   out in the range);
 * - `customers` who received it; `last_sold_at` its latest delivery in the range.
 *
 * Sorted by revenue, base units or return rate (highest first, then by code); the first page of 20 is the top 20.
 * A category filter keeps the SKUs of that category and its sub-categories, a customer group filter the sales to that
 * group's customers. Cached per tenant, filter set and page (D94).
 *
 * @phpstan-type ProductRow array{sku_id: int, product_id: int, product_name: string, sku_code: string, sku_name: string|null, is_bundle: bool, quantity: int, quantity_base: int, bundle_quantity_base: int, revenue: string, returns: string, returned_base: int, return_rate: string|null, customers: int, last_sold_at: string|null}
 */
final readonly class ProductPerformanceQuery
{
    public function __construct(
        private SalesFactsQuery $facts,
        private ReportCache $cache,
    ) {}

    /**
     * @return array{generated_at: string, page: LengthAwarePaginator<int, ProductRow>}
     */
    public function paginate(ReportFiltersData $filters, ProductSort $sort, int $page, int $perPage): array
    {
        return $this->cache->paginate(
            ReportName::Products,
            ['filters' => $filters, 'sort' => $sort->value],
            fn (): QueryBuilder => $this->builder($filters, $sort),
            $this->row(...),
            $page,
            $perPage,
        );
    }

    /**
     * Every row, for the export (not cached).
     *
     * @return Generator<int, ProductRow>
     */
    public function rows(ReportFiltersData $filters, ProductSort $sort): Generator
    {
        foreach ($this->builder($filters, $sort)->cursor() as $row) {
            yield $this->row($row);
        }
    }

    public function builder(ReportFiltersData $filters, ProductSort $sort): QueryBuilder
    {
        $start = BusinessCalendar::dayStart($filters->from, $filters->timezone);
        $end = BusinessCalendar::dayEnd($filters->to, $filters->timezone);
        $path = $filters->categoryId === null ? null : $this->facts->categoryPath($filters->categoryId);
        $facts = $this->facts($filters, $start, $end);

        $query = $facts->newQuery()
            ->fromSub($facts, 'u')
            ->join('skus', 'skus.id', '=', 'u.sku_id')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->when($filters->categoryId !== null, fn (QueryBuilder $query): QueryBuilder => is_string($path)
                ? $query->join('categories', 'categories.id', '=', 'products.category_id')->where('categories.path', 'like', $path.'%')
                : $query->whereRaw('1 = 0'))
            ->groupBy('u.sku_id', 'skus.code', 'skus.name', 'skus.product_id', 'products.name', 'products.type')
            ->select(['u.sku_id', 'skus.code as sku_code', 'skus.name as sku_name', 'skus.product_id', 'products.name as product_name', 'products.type as product_type'])
            ->selectRaw('sum(u.quantity) as quantity')
            ->selectRaw('sum(u.quantity_base) as quantity_base')
            ->selectRaw('sum(u.bundle_quantity_base) as bundle_quantity_base')
            ->selectRaw('round(sum(u.revenue) - sum(u.returns), 2) as revenue')
            ->selectRaw('round(sum(u.returns), 2) as returns')
            ->selectRaw('sum(u.returned_base) as returned_base')
            ->selectRaw('case when sum(u.quantity_base) > 0 then round(sum(u.returned_base) * 100.0 / sum(u.quantity_base), 2) end as return_rate')
            ->selectRaw('count(distinct u.customer_id) as customers')
            ->selectRaw('max(u.at) as last_sold_at');

        $query->orderByRaw(match ($sort) {
            ProductSort::Revenue => 'round(sum(u.revenue) - sum(u.returns), 2) desc',
            ProductSort::Quantity => 'sum(u.quantity_base) desc',
            ProductSort::ReturnRate => 'case when sum(u.quantity_base) > 0 then sum(u.returned_base) * 1.0 / sum(u.quantity_base) end desc nulls last',
        });

        return $query->orderBy('skus.code')->orderBy('u.sku_id');
    }

    /**
     * One row per fact, the same columns in every part: own lines, component lines, returned lines (by the SKU that
     * came back) and returned amounts (by the SKU of the top-level line).
     */
    private function facts(ReportFiltersData $filters, CarbonImmutable $start, CarbonImmutable $end): QueryBuilder
    {
        $lines = $this->facts->scoped($this->facts->lines($start, $end), $filters, byCategory: false)
            ->select(['f.sku_id', 'f.customer_id', 'f.quantity', 'f.quantity_base'])
            ->selectRaw('0 as bundle_quantity_base, f.gross - f.line_discount - f.order_discount as revenue, 0 as returned_base, 0 as returns, f.at');
        $components = $this->facts->scoped($this->facts->components($start, $end), $filters, byCategory: false)
            ->select(['f.sku_id', 'f.customer_id'])
            ->selectRaw('0 as quantity, f.quantity_base, f.quantity_base as bundle_quantity_base, 0 as revenue, 0 as returned_base, 0 as returns, f.at');
        $returned = $this->facts->scoped($this->facts->returns($start, $end), $filters, byCategory: false)
            ->select(['f.sku_id'])
            ->selectRaw('null::bigint as customer_id, 0 as quantity, 0 as quantity_base, 0 as bundle_quantity_base, 0 as revenue, f.quantity_base as returned_base, 0 as returns, null::timestamp as at');
        $refunded = $this->facts->scoped($this->facts->returns($start, $end), $filters, byCategory: false)
            ->select(['f.top_sku_id as sku_id'])
            ->selectRaw('null::bigint as customer_id, 0 as quantity, 0 as quantity_base, 0 as bundle_quantity_base, 0 as revenue, 0 as returned_base, f.amount as returns, null::timestamp as at');

        return $lines->unionAll($components)->unionAll($returned)->unionAll($refunded);
    }

    /**
     * @return ProductRow
     */
    private function row(object $row): array
    {
        /** @var object{sku_id: int|string, sku_code: string, sku_name: string|null, product_id: int|string, product_name: string, product_type: string, quantity: int|string, quantity_base: int|string, bundle_quantity_base: int|string, revenue: string, returns: string, returned_base: int|string, return_rate: string|null, customers: int|string, last_sold_at: string|null} $row */
        return [
            'sku_id' => (int) $row->sku_id,
            'product_id' => (int) $row->product_id,
            'product_name' => $row->product_name,
            'sku_code' => $row->sku_code,
            'sku_name' => $row->sku_name,
            'is_bundle' => $row->product_type === ProductType::Bundle->value,
            'quantity' => (int) $row->quantity,
            'quantity_base' => (int) $row->quantity_base,
            'bundle_quantity_base' => (int) $row->bundle_quantity_base,
            'revenue' => Money::add('0.00', $row->revenue),
            'returns' => Money::add('0.00', $row->returns),
            'returned_base' => (int) $row->returned_base,
            'return_rate' => $row->return_rate === null ? null : Money::add('0.00', $row->return_rate),
            'customers' => (int) $row->customers,
            'last_sold_at' => $row->last_sold_at === null ? null : CarbonImmutable::parse($row->last_sold_at, 'UTC')->toIso8601String(),
        ];
    }
}
