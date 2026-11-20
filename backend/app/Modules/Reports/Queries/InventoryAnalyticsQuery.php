<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Queries\MovementSummaryQuery;
use App\Modules\Reports\Data\ReportFiltersData;
use App\Modules\Reports\Enums\ReportName;
use App\Modules\Reports\Http\Requests\ReportFiltersRequest;
use App\Modules\Reports\Services\ReportCache;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * GET /api/dashboard/reports/inventory (V2 Task 16, D94): per SKU of the store (bundles have no stock, D93) its stock
 * now — on hand, available (BR-INV02) and the stock value at the base price of its default unit per base unit
 * («قيمة المخزون بسعر البيع»: no purchase cost is tracked, D94) — the base units that went out in the range (its own
 * lines and inside bundles, by `delivered_at`), the days of cover (available ÷ the average daily sales of the range;
 * null when nothing sold, shown as ∞), its last sale ever and the dead-stock flag (stock on hand and no sale for more
 * than `dead_days`, default 60), and its stock movements of the range by type (BR-INV06). The summary adds them up
 * over every SKU of the filter. Sorted by stock value, highest first, then code; `dead_only` keeps the dead stock. A
 * category filter keeps the SKUs of that category and its sub-categories. Cached per tenant, filter set and page.
 *
 * @phpstan-type Movements array{opening: int, adjustment: int, sale: int, return: int, receive: int}
 * @phpstan-type InventoryRow array{sku_id: int, product_id: int, product_name: string, sku_code: string, sku_name: string|null, on_hand: int, available: int, stock_value: string, sold_base: int, days_of_cover: float|null, last_sold_at: string|null, dead: bool, movements: Movements}
 * @phpstan-type InventorySummary array{skus: int, on_hand: int, stock_value: string, dead_count: int, dead_value: string, sold_base: int, movements: Movements}
 */
final readonly class InventoryAnalyticsQuery
{
    public function __construct(
        private SalesFactsQuery $facts,
        private MovementSummaryQuery $movements,
        private ReportCache $cache,
    ) {}

    /**
     * @return array{generated_at: string, page: LengthAwarePaginator<int, InventoryRow>}
     */
    public function paginate(ReportFiltersData $filters, int $deadDays, bool $deadOnly, int $page, int $perPage): array
    {
        return $this->cache->paginate(
            ReportName::Inventory,
            ['filters' => $filters, 'dead_days' => $deadDays, 'dead_only' => $deadOnly],
            fn (): QueryBuilder => $this->builder($filters, $deadDays, $deadOnly)->orderByDesc('stock_value')->orderBy('sku_code')->orderBy('sku_id'),
            $this->row(...),
            $page,
            $perPage,
        );
    }

    /**
     * The totals over every SKU of the filter (cached with the page).
     *
     * @return array{generated_at: string, data: InventorySummary}
     */
    public function summary(ReportFiltersData $filters, int $deadDays, bool $deadOnly): array
    {
        return $this->cache->remember(ReportName::Inventory, ['summary' => true, 'filters' => $filters, 'dead_days' => $deadDays, 'dead_only' => $deadOnly], function () use ($filters, $deadDays, $deadOnly): array {
            $base = $this->builder($filters, $deadDays, $deadOnly);
            $totals = $base->newQuery()
                ->fromSub($base, 'i')
                ->selectRaw('count(*) as skus, coalesce(sum(i.on_hand), 0) as on_hand, coalesce(sum(i.stock_value), 0) as stock_value, coalesce(sum(i.sold_base), 0) as sold_base')
                ->selectRaw('count(*) filter (where i.dead) as dead_count, coalesce(sum(i.stock_value) filter (where i.dead), 0) as dead_value')
                ->selectRaw('coalesce(sum(i.opening), 0) as opening, coalesce(sum(i.adjustment), 0) as adjustment, coalesce(sum(i.sale), 0) as sale, coalesce(sum(i.return), 0) as "return", coalesce(sum(i.receive), 0) as receive')
                ->first();
            /** @var object{skus: int|string, on_hand: int|string, stock_value: string, sold_base: int|string, dead_count: int|string, dead_value: string, opening: int|string, adjustment: int|string, sale: int|string, return: int|string, receive: int|string} $totals */

            return [
                'skus' => (int) $totals->skus,
                'on_hand' => (int) $totals->on_hand,
                'stock_value' => Money::add('0.00', $totals->stock_value),
                'dead_count' => (int) $totals->dead_count,
                'dead_value' => Money::add('0.00', $totals->dead_value),
                'sold_base' => (int) $totals->sold_base,
                'movements' => $this->movementsOf($totals),
            ];
        });
    }

    /**
     * Every row, for the export (not cached).
     *
     * @return Generator<int, InventoryRow>
     */
    public function rows(ReportFiltersData $filters, int $deadDays, bool $deadOnly): Generator
    {
        foreach ($this->builder($filters, $deadDays, $deadOnly)->orderByDesc('stock_value')->orderBy('sku_code')->orderBy('sku_id')->cursor() as $row) {
            yield $this->row($row);
        }
    }

    public function builder(ReportFiltersData $filters, int $deadDays, bool $deadOnly): QueryBuilder
    {
        $start = BusinessCalendar::dayStart($filters->from, $filters->timezone);
        $end = BusinessCalendar::dayEnd($filters->to, $filters->timezone);
        $days = max(1, (int) round($start->diffInDays($end->addSecond())));
        $deadBefore = CarbonImmutable::now()->subDays($deadDays);
        $path = $filters->categoryId === null ? null : $this->facts->categoryPath($filters->categoryId);
        $sold = $this->sold($start, $end);
        $available = 'coalesce(sku_inventory.on_hand - sku_inventory.reserved, 0)';
        $stockValue = 'round(coalesce(sku_inventory.on_hand, 0) * coalesce(unit.base_price, 0) / coalesce(unit.conversion_factor, 1), 2)';
        $dead = 'coalesce(sku_inventory.on_hand, 0) > 0 and (sold.last_sold_at is null or sold.last_sold_at < ?)';

        $query = Sku::query()->toBase()
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->where('products.type', '!=', ProductType::Bundle->value)
            ->leftJoin('sku_inventory', 'sku_inventory.sku_id', '=', 'skus.id')
            ->leftJoin('sku_units as unit', fn (JoinClause $join): JoinClause => $join->on('unit.sku_id', '=', 'skus.id')->where('unit.is_default', true))
            ->leftJoinSub($sold, 'sold', 'sold.sku_id', '=', 'skus.id')
            ->leftJoinSub($this->movements->bySku($start, $end), 'moves', 'moves.sku_id', '=', 'skus.id')
            ->when($filters->categoryId !== null, fn (QueryBuilder $query): QueryBuilder => is_string($path)
                ? $query->join('categories', 'categories.id', '=', 'products.category_id')->where('categories.path', 'like', $path.'%')
                : $query->whereRaw('1 = 0'))
            ->when($deadOnly, fn (QueryBuilder $query): QueryBuilder => $query->whereRaw($dead, [$deadBefore]))
            ->select(['skus.id as sku_id', 'skus.code as sku_code', 'skus.name as sku_name', 'skus.product_id', 'products.name as product_name', 'sold.last_sold_at'])
            ->selectRaw('coalesce(sku_inventory.on_hand, 0) as on_hand')
            ->selectRaw("{$available} as available")
            ->selectRaw("{$stockValue} as stock_value")
            ->selectRaw('coalesce(sold.quantity_base, 0) as sold_base')
            ->selectRaw("case when coalesce(sold.quantity_base, 0) > 0 then round(greatest({$available}, 0) * {$days}.0 / sold.quantity_base, 1) end as days_of_cover")
            ->selectRaw("({$dead}) as dead", [$deadBefore]);

        foreach (MovementType::cases() as $type) {
            $query->selectRaw("coalesce(moves.\"{$type->value}\", 0) as \"{$type->value}\"");
        }

        return $query;
    }

    /**
     * Per SKU: the base units that went out in the range (own lines that are not bundle lines, and component lines) and
     * the latest delivery ever.
     */
    private function sold(CarbonImmutable $start, CarbonImmutable $end): QueryBuilder
    {
        $ever = BusinessCalendar::dayStart(ReportFiltersRequest::EARLIEST, 'UTC');
        $now = CarbonImmutable::now();
        $lines = $this->facts->lines($ever, $now->max($end));
        $own = $lines->newQuery()->fromSub($lines, 'l')->where('l.is_bundle', false)->select(['l.sku_id', 'l.at', 'l.quantity_base']);
        $components = $this->facts->components($ever, $now->max($end));
        $facts = $own->unionAll($components->newQuery()->fromSub($components, 'c')->select(['c.sku_id', 'c.at', 'c.quantity_base']));

        return $facts->newQuery()
            ->fromSub($facts, 's')
            ->select('s.sku_id')
            ->selectRaw('sum(s.quantity_base) filter (where s.at between ? and ?) as quantity_base', [$start, $end])
            ->selectRaw('max(s.at) as last_sold_at')
            ->groupBy('s.sku_id');
    }

    /**
     * @return InventoryRow
     */
    private function row(object $row): array
    {
        /** @var object{sku_id: int|string, sku_code: string, sku_name: string|null, product_id: int|string, product_name: string, on_hand: int|string, available: int|string, stock_value: string, sold_base: int|string, days_of_cover: string|null, last_sold_at: string|null, dead: bool, opening: int|string, adjustment: int|string, sale: int|string, return: int|string, receive: int|string} $row */
        return [
            'sku_id' => (int) $row->sku_id,
            'product_id' => (int) $row->product_id,
            'product_name' => $row->product_name,
            'sku_code' => $row->sku_code,
            'sku_name' => $row->sku_name,
            'on_hand' => (int) $row->on_hand,
            'available' => (int) $row->available,
            'stock_value' => Money::add('0.00', $row->stock_value),
            'sold_base' => (int) $row->sold_base,
            'days_of_cover' => $row->days_of_cover === null ? null : (float) $row->days_of_cover,
            'last_sold_at' => $row->last_sold_at === null ? null : CarbonImmutable::parse($row->last_sold_at, 'UTC')->toIso8601String(),
            'dead' => (bool) $row->dead,
            'movements' => $this->movementsOf($row),
        ];
    }

    /**
     * @param  object{opening: int|string, adjustment: int|string, sale: int|string, return: int|string, receive: int|string}  $row
     * @return Movements
     */
    private function movementsOf(object $row): array
    {
        return ['opening' => (int) $row->opening, 'adjustment' => (int) $row->adjustment, 'sale' => (int) $row->sale, 'return' => (int) $row->return, 'receive' => (int) $row->receive];
    }
}
