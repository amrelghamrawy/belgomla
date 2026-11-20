<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Reports\Data\BundleReportData;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * GET /api/dashboard/reports/bundles (BR-P15, D93): over the orders delivered in the range (by `delivered_at`, business
 * days, D60, like the sales report), the live bundle lines (`is_bundle`) summed per bundle product (bundles that went
 * out and their line totals), and the live component lines (`parent_item_id` set) summed per SKU in base units (what
 * went out × the line's conversion factor). Names and codes are the latest line's snapshot. A bundle or component of
 * which nothing went out is left out. Returns are not taken off.
 *
 * @phpstan-type BundleRow array{product_id: int, name: string, sku_code: string, quantity: int, total: string}
 * @phpstan-type ComponentRow array{sku_id: int, sku_code: string, product_name: string, quantity_base: int}
 * @phpstan-type BundleReport array{bundles: list<BundleRow>, components: list<ComponentRow>}
 */
final readonly class BundleSalesQuery
{
    /** What went out of a line in its selling units; a line of an order delivered before BR-O13 has no count, all of it went. */
    private const string DELIVERED = 'coalesce(order_items.delivered_quantity, order_items.quantity)';

    /**
     * @return BundleReport
     */
    public function get(BundleReportData $report): array
    {
        return ['bundles' => $this->bundles($report), 'components' => $this->components($report)];
    }

    /**
     * @return list<BundleRow>
     */
    private function bundles(BundleReportData $report): array
    {
        $rows = $this->delivered($report)
            ->where('order_items.is_bundle', true)
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, max(order_items.id) as latest, sum('.self::DELIVERED.') as quantity, sum(order_items.line_total) as total')
            ->havingRaw('sum('.self::DELIVERED.') > 0')
            ->orderByDesc('quantity')
            ->orderBy('order_items.product_id')
            ->get();
        $latest = $this->latest($rows->pluck('latest')->all());
        $bundles = [];

        foreach ($rows as $row) {
            /** @var object{product_id: int|string, latest: int|string, quantity: int|string, total: string} $row */
            $line = $latest[(int) $row->latest] ?? null;
            $bundles[] = [
                'product_id' => (int) $row->product_id,
                'name' => $line->product_name ?? '',
                'sku_code' => $line->sku_code ?? '',
                'quantity' => (int) $row->quantity,
                'total' => Money::add('0.00', $row->total),
            ];
        }

        return $bundles;
    }

    /**
     * @return list<ComponentRow>
     */
    private function components(BundleReportData $report): array
    {
        $rows = $this->delivered($report)
            ->whereNotNull('order_items.parent_item_id')
            ->groupBy('order_items.sku_id')
            ->selectRaw('order_items.sku_id, max(order_items.id) as latest, sum('.self::DELIVERED.' * order_items.conversion_factor) as quantity_base')
            ->havingRaw('sum('.self::DELIVERED.' * order_items.conversion_factor) > 0')
            ->orderByDesc('quantity_base')
            ->orderBy('order_items.sku_id')
            ->get();
        $latest = $this->latest($rows->pluck('latest')->all());
        $components = [];

        foreach ($rows as $row) {
            /** @var object{sku_id: int|string, latest: int|string, quantity_base: int|string} $row */
            $line = $latest[(int) $row->latest] ?? null;
            $components[] = [
                'sku_id' => (int) $row->sku_id,
                'sku_code' => $line->sku_code ?? '',
                'product_name' => $line->product_name ?? '',
                'quantity_base' => (int) $row->quantity_base,
            ];
        }

        return $components;
    }

    /** The tenant's live order lines of the orders delivered in the range. */
    private function delivered(BundleReportData $report): QueryBuilder
    {
        return OrderItem::query()->toBase()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', OrderStatus::Delivered->value)
            ->whereBetween('orders.delivered_at', [
                BusinessCalendar::dayStart($report->from, $report->timezone),
                BusinessCalendar::dayEnd($report->to, $report->timezone),
            ])
            ->whereNull('order_items.removed_at');
    }

    /**
     * The snapshot names of the given lines, by id.
     *
     * @param  array<int|string, mixed>  $ids
     * @return array<int, OrderItem>
     */
    private function latest(array $ids): array
    {
        $lines = [];

        foreach (OrderItem::query()->whereKey($ids)->get(['id', 'product_name', 'sku_code']) as $line) {
            $lines[$line->id] = $line;
        }

        return $lines;
    }
}
