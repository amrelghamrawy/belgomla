<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use Illuminate\Support\Facades\DB;

/**
 * BR-P05 (V2 Task 14): which of the given SKUs have history, so a variant removed from the product form is set inactive
 * instead of deleted. History = an order line, a stock movement (an opening stock above zero is one), a negotiated
 * customer price or a promotion row: rows that point at the SKU without cascading. A cart line or an empty stock row is
 * not history (they go with the SKU). The tables belong to Orders, Inventory and Pricing; only their `sku_id` is read,
 * through the query builder, so Catalog does not depend on those modules' models. A unit of the SKU being a bundle
 * component (`bundle_items`, D93) counts too: the bundle needs it, and its foreign key refuses the delete.
 */
final readonly class SkuHistoryQuery
{
    private const array TABLES = ['order_items', 'inventory_movements', 'customer_sku_prices', 'promotion_skus'];

    /**
     * @param  list<int>  $skuIds
     * @return list<int> the ones with history
     */
    public function withHistory(array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }

        $found = [];

        foreach (self::TABLES as $table) {
            foreach (DB::table($table)->whereIn('sku_id', $skuIds)->distinct()->pluck('sku_id') as $id) {
                $found[] = (int) (is_numeric($id) ? $id : 0);
            }
        }

        foreach (DB::table('bundle_items')->join('sku_units', 'sku_units.id', '=', 'bundle_items.component_sku_unit_id')->whereIn('sku_units.sku_id', $skuIds)->distinct()->pluck('sku_units.sku_id') as $id) {
            $found[] = (int) (is_numeric($id) ? $id : 0);
        }

        return array_values(array_unique($found));
    }
}
