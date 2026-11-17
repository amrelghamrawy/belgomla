<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Catalog\Models\Category;
use App\Modules\Orders\Enums\OrderKind;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderReturnItem;
use App\Modules\Reports\Data\ReportFiltersData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;

/**
 * The line-level facts the advanced reports aggregate (V2 Task 16, D94), one row per order or return line, each with
 * the customer, their current group, the product's section (D68) and category path, and the moment that dates it.
 *
 * - `lines()`: the top-level lines (loose lines and bundle lines, BR-P15; never the component lines, which are not in
 *   the items total) of the orders delivered in the range, by `delivered_at`. Removed lines (BR-O12) are out;
 *   pre-orders are never delivered (D88), and are excluded anyway. Per line: what went out (`quantity`, selling
 *   units, and `quantity_base`), `gross` = list price × what went out (before any manual discount), the line discount
 *   (`line_discount` = gross − line_total, BR-PR08) and the line's share of the order discount (`order_discount` =
 *   line_total × discount_amount / items_total, D85), so Σ (gross − line_discount − order_discount) over an order is
 *   its `order_total` (BR-O08).
 * - `components()`: the component lines of the bundles in those orders, with the base units that went out.
 * - `returns()`: the lines of the live returns recorded in the range, by `returned_at` (BR-R05): the returned line's
 *   own SKU and base units, and the amount (its `line_total`, after the discounts, D85) with the section and SKU of
 *   the top-level line it belongs to (a returned component counts against its bundle's revenue).
 *
 * - `costs()` (V2 costing, D98): what the goods that went out cost, one row per line that left the stock (loose lines and
 *   component lines; a bundle line holds nothing, D93) at the cost frozen on it at delivery (D96 f), counted against the
 *   top-level line's SKU and product (a component's cost against its bundle, where the revenue is); `uncosted` = 1 when
 *   the line has no cost.
 * - `returnedCosts()`: what the live returns of the range brought back, at the returned line's frozen cost (BR-INV09),
 *   against the same top-level line.
 *
 * Amounts are unrounded numerics; the reports round their sums to the piaster.
 */
final readonly class SalesFactsQuery
{
    /** Selling units that went out; a line of an order delivered before BR-O13 carries no count: all of it went. */
    private const string WENT_OUT = 'coalesce(order_items.delivered_quantity, order_items.quantity)';

    public function lines(CarbonImmutable $start, CarbonImmutable $end): QueryBuilder
    {
        return $this->delivered($start, $end)
            ->whereNull('order_items.parent_item_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->select([
                'order_items.id', 'order_items.order_id', 'orders.customer_id', 'customers.customer_group_id', 'order_items.sku_id', 'order_items.product_id', 'order_items.is_bundle',
                'orders.delivered_at as at', 'categories.path as category_path',
            ])
            ->selectRaw("coalesce(categories.kind, 'general') as kind")
            ->selectRaw(self::WENT_OUT.' as quantity')
            ->selectRaw(self::WENT_OUT.' * order_items.conversion_factor as quantity_base')
            ->selectRaw('order_items.list_unit_price * '.self::WENT_OUT.' as gross')
            ->selectRaw('order_items.list_unit_price * '.self::WENT_OUT.' - order_items.line_total as line_discount')
            ->selectRaw('case when orders.items_total > 0 then order_items.line_total * orders.discount_amount / orders.items_total else 0 end as order_discount');
    }

    public function costs(CarbonImmutable $start, CarbonImmutable $end): QueryBuilder
    {
        return $this->delivered($start, $end)
            ->where(fn (QueryBuilder $query): QueryBuilder => $query->whereNotNull('order_items.parent_item_id')->orWhere('order_items.is_bundle', false))
            ->leftJoin('order_items as parent', 'parent.id', '=', 'order_items.parent_item_id')
            ->join('products', 'products.id', '=', new Expression('coalesce(parent.product_id, order_items.product_id)'))
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->select(['order_items.id', 'order_items.order_id', 'orders.customer_id', 'customers.customer_group_id', 'orders.delivered_at as at', 'categories.path as category_path'])
            ->selectRaw('coalesce(parent.sku_id, order_items.sku_id) as top_sku_id')
            ->selectRaw("coalesce(categories.kind, 'general') as kind")
            ->selectRaw('coalesce(order_items.unit_cost, 0) * '.self::WENT_OUT.' * order_items.conversion_factor as cost')
            ->selectRaw('case when order_items.unit_cost is null then 1 else 0 end as uncosted');
    }

    public function returnedCosts(CarbonImmutable $start, CarbonImmutable $end): QueryBuilder
    {
        $returns = $this->returns($start, $end);

        return $returns->newQuery()
            ->fromSub($returns->addSelect('returned.unit_cost'), 'r')
            ->select(['r.id', 'r.order_id', 'r.customer_id', 'r.customer_group_id', 'r.at', 'r.category_path', 'r.kind', 'r.top_sku_id'])
            ->selectRaw('coalesce(r.unit_cost, 0) * r.quantity_base as cost');
    }

    public function components(CarbonImmutable $start, CarbonImmutable $end): QueryBuilder
    {
        return $this->delivered($start, $end)
            ->whereNotNull('order_items.parent_item_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->select(['order_items.id', 'order_items.order_id', 'orders.customer_id', 'customers.customer_group_id', 'order_items.sku_id', 'order_items.product_id', 'orders.delivered_at as at', 'categories.path as category_path'])
            ->selectRaw(self::WENT_OUT.' * order_items.conversion_factor as quantity_base');
    }

    public function returns(CarbonImmutable $start, CarbonImmutable $end): QueryBuilder
    {
        return OrderReturnItem::query()->toBase()
            ->join('order_returns', 'order_returns.id', '=', 'order_return_items.order_return_id')
            ->join('order_items as returned', 'returned.id', '=', 'order_return_items.order_item_id')
            ->join('order_items as top', 'top.id', '=', new Expression('coalesce(returned.parent_item_id, returned.id)'))
            ->join('orders', 'orders.id', '=', 'order_returns.order_id')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->join('products', 'products.id', '=', 'top.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereNull('order_returns.voided_at')
            ->whereBetween('order_returns.returned_at', [$start, $end])
            ->select([
                'order_return_items.id', 'order_returns.order_id', 'orders.customer_id', 'customers.customer_group_id',
                'returned.sku_id', 'returned.product_id', 'top.sku_id as top_sku_id', 'top.product_id as top_product_id',
                'order_returns.returned_at as at', 'order_return_items.quantity_base', 'order_return_items.line_total as amount', 'categories.path as category_path',
            ])
            ->selectRaw("coalesce(categories.kind, 'general') as kind");
    }

    /**
     * The facts as the subquery `f`, narrowed to the category asked (with its sub-categories, by the materialised path,
     * BR-P06; an unknown category matches nothing; left to the caller when `$byCategory` is false) and to the
     * customers of the group asked.
     */
    public function scoped(QueryBuilder $facts, ReportFiltersData $filters, bool $byCategory = true): QueryBuilder
    {
        $path = $filters->categoryId === null || ! $byCategory ? null : $this->categoryPath($filters->categoryId);

        return $facts->newQuery()
            ->fromSub($facts, 'f')
            ->when($byCategory && $filters->categoryId !== null, fn (QueryBuilder $query): QueryBuilder => is_string($path)
                ? $query->where('f.category_path', 'like', $path.'%')
                : $query->whereRaw('1 = 0'))
            ->when($filters->customerGroupId, fn (QueryBuilder $query, int $group): QueryBuilder => $query->where('f.customer_group_id', $group));
    }

    /** The materialised path of a category of the tenant (BR-P06), null when it has none. */
    public function categoryPath(int $categoryId): ?string
    {
        $path = Category::query()->whereKey($categoryId)->value('path');

        return is_string($path) ? $path : null;
    }

    /** The live lines of the tenant's orders delivered in the range, with the customer. */
    private function delivered(CarbonImmutable $start, CarbonImmutable $end): QueryBuilder
    {
        return OrderItem::query()->toBase()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->where('orders.status', OrderStatus::Delivered->value)
            ->where('orders.kind', OrderKind::Order->value)
            ->whereBetween('orders.delivered_at', [$start, $end])
            ->whereNull('order_items.removed_at');
    }
}
